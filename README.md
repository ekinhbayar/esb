Webgriffe ESB
=============

Simple, beanstalkd powered, ESB framework.

[![Build Status](https://travis-ci.org/webgriffe/esb.svg?branch=master)](https://travis-ci.org/webgriffe/esb)

Introduction
------------

Webgriffe ESB is a PHP framework that aims to speed up the development of [Enterprise Service Buses](https://en.wikipedia.org/wiki/Enterprise_service_bus).

It uses [Beanstalkd](http://kr.github.io/beanstalkd/) as a queue engine and it's built on top of popular open-sourced libraries like:

* [Amp](http://amphp.org/)
* [Symfony's Dependency Injection](http://symfony.com/doc/current/components/dependency_injection.html)
* [Monolog](https://github.com/Seldaek/monolog)

Architecture & Core concepts
----------------------------

Integrating different systems together is a matter of data flows. With Webgriffe ESB every data flow goes, one way, from a system to another through a Beanstalkd **tube**. Every tube must have a **producer** which produces **jobs** and a **worker** which works that jobs. So data goes from the producer to the worker through the tube.

With Webgriffe ESB you integrate different systems by only implementing workers and producers. The framework will take care about the rest.

Webgriffe ESB is designed to use a single binary which is used as a main entry point of the whole application; all the producers and workers are started and executed by a single PHP binary. This is possible by using [Amp](http://amphp.org/) concurrency framework.

Installation
------------
Require this package using [Composer](https://getcomposer.org/):

```bash
composer require webgriffe/esb dev-master
```

Configuration
-------------
Copy the sample configuration file into your ESB root directory:

```bash
cp vendor/webgriffe/esb/esb.yml.sample ./esb.yml
```

The `esb.yml` file is the main configuration of your ESB application, where you have to register workers and producers. All the services implementing 
[WorkerInterface](https://github.com/webgriffe/esb/blob/master/src/WorkerInterface.php) and [ProducerInterface](https://github.com/webgriffe/esb/blob/master/src/ProducerInterface.php) are registered automatically as workers and producers. Refer to the [Symfony Dependency Injection](http://symfony.com/doc/current/components/dependency_injection.html) component documentation and the [sample configuration file](https://github.com/webgriffe/esb/blob/master/esb.yml.sample) for more information about configuration of your ESB services.

You also have to define some parameters under the `parameters` section, refer to the `esb.yml.sample` file for more informations about required parameters. Usually it's better to isolate parameters in a `parameters.yml` file which can be included in the `esb.yml` as follows:

```yaml
# esb.yml
imports:
  - { resource: parameters.yml}

services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: true
  
  My\Esb\Namespace\:
    resource: 'src/*'
```

```yaml
# parameters.yml
parameters:
  beanstalkd: tcp://127.0.0.1:11300
  # Other parameters here ...
```

Producers
---------

As said, a producer is a class which implements the `ProducerInterface`. Anyway implementing only the `ProducerInterface` is not enough. Every producer must implement also one of the supported *producer type* interfaces. This is because the framework must know when to invoke every producer. At the moment we support 3 producer types:

* `RepeatProducerInterface`: these producers are invoked repeatedly every fixed interval.
* `CrontabProducerInterface`: these producers are invoked when their [crontab expression](https://en.wikipedia.org/wiki/Cron#CRON_expression) match.
* `HttpRequestProducerInterface`: these producers are invoked when the ESB's HTTP server receives a corresponding HTTP request.

Refer to these interfaces in the source code for more information.
The `produce` method of the `ProducerInterface` must return an Amp's [Iterator](https://amphp.org/amp/iterators/), this allows you to produce a collection of jobs with a single `produce` invocation. Moreover iterators allows to have long running produce operations which are executed asyncronously.

Also, keep in mind that **you should never use I/O blocking function calls inside your producers**. Look for [Amp](https://amphp.org/) or [ReactPHP](https://reactphp.org) libraries when you need to do I/O operations. 

See the dummy producers in the [tests/](https://github.com/webgriffe/esb/tree/master/tests) directory for some examples.

Workers
-------

Workers are simpler than producers. They implement only the `WorkerInterface` and don't have *worker type* interfaces. This is because every worker is invoked immediatly when a job is available on its tube.

The `work` method of a worker must return an Amp's [Promise](https://amphp.org/amp/promises/) that must resolve when the job is worked succesfully. Otherwise the `work` must throw an exception.

When a worker successfully works a job the ESB framwork deletes it from the tube. Instead, when a worker fails to work a job the ESB framework keeps it in the tube for a maximum of 5 times then the job is buried and a critical event is logged.

Like for producers, **you should never use I/O blocking function calls inside your workers**. Look for [Amp](https://amphp.org/) or [ReactPHP](https://reactphp.org) libraries when you need to do I/O operations.

See the dummy workers in the [tests/](https://github.com/webgriffe/esb/tree/master/tests) directory for some examples.

Initialization
--------------

`WorkerInterface` and `ProducerInterface` support boths an `init` method which is called by the ESB framework at the boot phase.

The `init` method must return an Amp's [Promise](https://amphp.org/amp/promises/). This allows you to perform initialization operations asyncronously (for example instantiating a SOAP client with a remote WSDL URL).

Unit testing
------------

You can (and should) also unit test your workers and producers. Because workers and producers must return promises and iterators you have to use the Amp loop inside your tests. You should also use the [amphp/phpunit-util](https://github.com/amphp/phpunit-util) to reset the loop state between tests.

Unit test example
-----------------

Here follows an example of a producer test which verify that the producer produces stock inventory update jobs based on am XML file in a given directory.

```php
public function testShouldProduceMultipleJobsWithMultipleEntriesFile()
{
    filesystem(new BlockingDriver());
    vfsStream::setup();
    $this->importFile = vfsStream::url('root/stock.xml');
    $this->producer = new Stock($this->importFile);
    copy(__DIR__ . '/StockTestFixtures/multiple_entries.xml', $this->importFile);

    $this->jobs = [];
    Loop::run(
        function () use ($data) {
            $iterator = $this->producer->produce($data);
            while (yield $iterator->advance()) {
                $this->jobs[] = $iterator->getCurrent();
            }
        }
    );

    $this->assertCount(52, $this->jobs);
    $this->assertEquals(new Job(['sku' => 'SKU-1', 'qty' => 9519.000]), $this->jobs[0]);
    $this->assertEquals(new Job(['sku' => 'SKU-23', 'qty' => 299.000]), $this->jobs[12]);
    $this->assertEquals(new Job(['sku' => 'SKU-50', 'qty' => 2017.000]), $this->jobs[21]);
}

```

Here follows the example of a unit test for the related worker which takes the SKU and quantity to update from the job and then performs an API call to update the quantity.

```php
public function testWorksSimpleJob()
{
    $this->sessionId = random_int(1, 1000);
    $this->client = $this->prophesize(Client::class);
    $this->clientFactory = $this->prophesize(Factory::class);
    $this->clientFactory->create()->willReturn(new Success($this->client->reveal()));
    $this->worker = new Stock($this->clientFactory->reveal());

    $sku = 'SKU-1';
    $qty = 10;
    $this->client
        ->login()
        ->shouldBeCalled()
        ->willReturn(new Success($this->sessionId))
    ;
    $this->client
        ->call('cataloginventory_stock_item.update', [$sku, ['qty' => $qty, 'is_in_stock' => true]])
        ->shouldBeCalled()
        ->willReturn(new Success(true))
    ;
    $this->client->endSession()->shouldBeCalled()->willReturn(new Success());

    $job = new QueuedJob(1, ['sku' => $sku, 'qty' => $qty]);
    Loop::run(function () use ($job) {
        yield $this->worker->init();
        yield $this->worker->work($job);
    });
}
```

Deployment
----------
As said all workers and producers are managed by a single PHP binary. This binary is located at `vendor/bin/esb`. So to deploy and run your ESB application all you have to do is to deploy your application as any other PHP application (for example using [Deployer](https://deployer.org/)) and make sure that `vendor/bin/esb` is always running (we suggest to use [Supervisord](http://supervisord.org/) for this purpose).

Keep in mind that the `vendor/bin/esb` binary logs its operations to `stdout` and errors using `error_log()` function. With a standard PHP CLI configuration all the `error_log()` entries are then redirected to `stderr`. This is done through [Monolog](https://github.com/Seldaek/monolog)'s [StreamHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/StreamHandler.php) and [ErrorHandler](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/ErrorLogHandler.php) handlers. Moreover all critical events are handled by the [NativeMailHander](https://github.com/Seldaek/monolog/blob/master/src/Monolog/Handler/NativeMailerHandler.php) (configured with `critical_events_to ` and `critical_events_from` parameters).

You can also add your own handlers using the `esb.yml` configuration file.

Contributing
------------

To contribute simply fork this repository, do your changes and then propose a pull requests. The test suite requires a running instance of Beanstalkd:

```bash
beanstalkd &
vendor/bin/phpunit
```

By default it tries to connect to a Beanstalkd running on `127.0.0.1` and default port `11300`. If you have Beanstalkd running elsewhere (for example in a Docker container) you can set the `BEANSTALKD_CONNECTION_URI` environment variable with the connection string (like `tcp://docker:11300`).

License
-------
This library is under the MIT license. See the complete license in the LICENSE file.

Credits
-------
Developed by [Webgriffe®](http://www.webgriffe.com/).
