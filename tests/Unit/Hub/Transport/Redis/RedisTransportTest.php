<?php

declare(strict_types=1);

namespace Freddie\Tests\Unit\Hub\Transport\Redis;

use ArrayObject;
use Evenement\EventEmitter;
use Freddie\Hub\Transport\Redis\RedisTransport;
use Freddie\Message\Message;
use Freddie\Message\Update;
use React\EventLoop\Loop;

it('dispatches published updates', function () {
    $storage = new ArrayObject();
    $eventEmitter = new EventEmitter();
    $transport = new RedisTransport(
        new RedisClientStub($storage, $eventEmitter),
        new RedisClientStub($storage, $eventEmitter)
    );

    // Given
    $subscriber = (object) ['received' => null];
    $update = new Update(['/foo'], new Message('bar'));
    $callback = fn($receivedUpdate) => $subscriber->received = $receivedUpdate;

    // When
    $transport->subscribe($callback);
    $transport->publish($update);

    // Then
    expect($subscriber->received ?? null)->not()->toBe($update); // Because serialization/deserialization
    expect($subscriber->received ?? null)->toEqual($update);

    // When
    $transport->unsubscribe($callback);
    $transport->publish(new Update(['/foo'], new Message('foobar')));
    expect($subscriber->received ?? null)->toEqual($update);
});

it('performs state reconciliation', function () {
    $client = new RedisClientStub();
    $transport = new RedisTransport($client, clone $client, options: ['size' => 3]);

    // Given
    $updates = [
        new Update(['/foo'], new Message(id: '1')),
        new Update(['/foo'], new Message(id: '2')),
        new Update(['/foo'], new Message(id: '3')),
        new Update(['/foo'], new Message(id: '4')),
        new Update(['/foo'], new Message(id: '5')),
        new Update(['/foo'], new Message(id: '6')),
        new Update(['/foo'], new Message(id: '7')),
        new Update(['/foo'], new Message(id: '8')),
        new Update(['/foo'], new Message(id: '9')),
        new Update(['/foo'], new Message(id: '10')),
    ];

    foreach ($updates as $update) {
        $transport->publish($update);
    }

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate($transport::EARLIEST));

    // Then
    expect($missedUpdates)->toEqual(array_slice($updates, 7, 3));

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate('1'));

    // Then
    expect($missedUpdates)->toBe([]);

    // When
    $missedUpdates = iterator_to_array($transport->reconciliate('9'));

    // Then
    expect($missedUpdates)->toEqual([$updates[9]]);
});

it('periodically trims the database', function () {
    $client = new RedisClientStub();
    $transport = new RedisTransport($client, clone $client, options: ['size' => 3, 'trimInterval' => 0.01]);

    // Given
    $updates = [
        new Update(['/foo'], new Message(id: '1')),
        new Update(['/foo'], new Message(id: '2')),
        new Update(['/foo'], new Message(id: '3')),
        new Update(['/foo'], new Message(id: '4')),
        new Update(['/foo'], new Message(id: '5')),
        new Update(['/foo'], new Message(id: '6')),
        new Update(['/foo'], new Message(id: '7')),
        new Update(['/foo'], new Message(id: '8')),
        new Update(['/foo'], new Message(id: '9')),
        new Update(['/foo'], new Message(id: '10')),
    ];

    foreach ($updates as $update) {
        $transport->publish($update);
    }

    Loop::addTimer(0.02, fn () => Loop::stop());
    Loop::run();

    expect($client->storage->getArrayCopy()['mercureUpdates'])->toHaveCount(3);
});
