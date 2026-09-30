<?php

declare(strict_types=1);

/**
 * One contending allocator process for SequenceConcurrencyTest.
 *
 * usage: php allocation-worker.php <database-url> <start-at-microtime> <count> <name> <scope>...
 *
 * Waits for the shared start time so every worker hits the counter row at the
 * same moment — including the very first allocation, where they all race to
 * create it — then prints each value it was handed, one per line.
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Nubit\SequenceBundle\Sequence\SequenceAllocator;
use Nubit\SequenceBundle\Sequence\SequenceMetadata;
use Nubit\SequenceBundle\Sequence\SequenceScopeResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $url, $startAt, $count, $name] = $argv;
$scopes = array_slice($argv, 5);

$parser = new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']);
$connection = DriverManager::getConnection($parser->parse($url));
$config = ORMSetup::createAttributeMetadataConfig(
    [dirname(__DIR__, 3) . '/packages/sequence-bundle/src/Entity'],
    true,
);
if (PHP_VERSION_ID >= 80400) {
    // Symfony 8's var-exporter no longer ships LazyGhost; PHP 8.4 covers it natively.
    $config->enableNativeLazyObjects(true);
}
$entityManager = new EntityManager($connection, $config);

$allocator = new SequenceAllocator(
    $entityManager,
    new SequenceScopeResolver(PropertyAccess::createPropertyAccessor()),
    new SequenceMetadata(),
);

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

for ($i = 0; $i < (int) $count; ++$i) {
    foreach ($scopes as $scope) {
        echo $scope, ' ', $allocator->allocate($scope, $name), "\n";
    }
}
