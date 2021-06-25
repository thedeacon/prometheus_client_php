<?php

declare(strict_types=1);

namespace Prometheus\Storage;

use APCUIterator;
use Prometheus\Exception\StorageException;
use Prometheus\MetricFamilySamples;
use RuntimeException;

class APCng implements Adapter
{
    /** @var string Default prefix to use for APC keys. */
    const PROMETHEUS_PREFIX = 'prom';

    /** @var int Number of retries before we give on apcu_cas(). This can prevent an infinite-loop if we fill up APC. */
    const CAS_LOOP_RETRIES = 2000;

    /** @var int Number of seconds for cache object to live in APC. When new metrics are created by other threads, this is the maximum delay until they are discovered.
                 Setting this to a value less than 1 will disable the cache, which will negatively impact performance when making multiple collect*() function-calls.
                 If more than a few thousand metrics are being tracked, disabling cache will be faster, due to apcu_store/fetch serialization being slow. */
    private $metainfoCacheTTL = 1;

    /** @var string APC key where array of all discovered+created metainfo keys is stored */
    private $metainfoCacheKey;

    /** @var string Prefix to use for APC keys. */
    private $prometheusPrefix;

    /**
     * APCng constructor.
     *
     * @param string $prometheusPrefix Prefix for APCu keys (defaults to {@see PROMETHEUS_PREFIX}).
     *
     * @throws StorageException
     */
    public function __construct(string $prometheusPrefix = self::PROMETHEUS_PREFIX)
    {
        if (!extension_loaded('apcu')) {
            throw new StorageException('APCu extension is not loaded');
        }
        if (!apcu_enabled()) {
            throw new StorageException('APCu is not enabled');
        }

        $this->prometheusPrefix = $prometheusPrefix;
        $this->metainfoCacheKey = implode(':', [ $this->prometheusPrefix, 'metainfocache' ]);
    }

    /**
     * @return MetricFamilySamples[]
     */
    public function collect(): array
    {
        $metrics = $this->collectHistograms();
        $metrics = array_merge($metrics, $this->collectGauges());
        $metrics = array_merge($metrics, $this->collectCounters());
        return $metrics;
    }

    /**
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateHistogram(array $data): void
    {
        // Initialize the sum
        $sumKey = $this->histogramBucketValueKey($data, 'sum');
        $new = apcu_add($sumKey, $this->toBinaryRepresentationAsInteger(0));

        // If sum does not exist, assume a new histogram and store the metadata
        if ($new) {
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            $this->storeLabelKeys($data);
        }

        // Atomically increment the sum
        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $done = false;
        $loopCatcher = self::CAS_LOOP_RETRIES;
        while (!$done && $loopCatcher-- > 0) {
            $old = apcu_fetch($sumKey);
            if ($old !== false) {
                $done = apcu_cas($sumKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
            }
        }
        if ($loopCatcher <= 0) {
            throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
        }

        // Figure out in which bucket the observation belongs
        $bucketToIncrease = '+Inf';
        foreach ($data['buckets'] as $bucket) {
            if ($data['value'] <= $bucket) {
                $bucketToIncrease = $bucket;
                break;
            }
        }

        // Initialize and increment the bucket
        apcu_add($this->histogramBucketValueKey($data, $bucketToIncrease), 0);
        apcu_inc($this->histogramBucketValueKey($data, $bucketToIncrease));
    }

    /**
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateGauge(array $data): void
    {
        $valueKey = $this->valueKey($data);
        if ($data['command'] === Adapter::COMMAND_SET) {
            apcu_store($valueKey, $this->toBinaryRepresentationAsInteger($data['value']));
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            $this->storeLabelKeys($data);
        } else {
            $new = apcu_add($valueKey, $this->toBinaryRepresentationAsInteger(0));
            if ($new) {
                apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
                $this->storeLabelKeys($data);

            }
            // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
            $done = false;
            $loopCatcher = self::CAS_LOOP_RETRIES;
            while (!$done && $loopCatcher-- > 0) {
                $old = apcu_fetch($valueKey);
                if ($old !== false) {
                    $done = apcu_cas($valueKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
                }
            }
            if ($loopCatcher <= 0) {
                throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
            }
        }
    }

    /**
     * @param mixed[] $data
     * @throws RuntimeException
     */
    public function updateCounter(array $data): void
    {
        $valueKey = $this->valueKey($data);
        // Check if value key already exists
        if (apcu_exists($valueKey) === false) {
            apcu_add($valueKey, 0);
            apcu_store($this->metaKey($data), json_encode($this->metaData($data)));
            $this->storeLabelKeys($data);
        }

        // Taken from https://github.com/prometheus/client_golang/blob/66058aac3a83021948e5fb12f1f408ff556b9037/prometheus/value.go#L91
        $done = false;
        $loopCatcher = self::CAS_LOOP_RETRIES;
        while (!$done && $loopCatcher-- > 0) {
            $old = apcu_fetch($valueKey);
            if ($old !== false) {
                $done = apcu_cas($valueKey, $old, $this->toBinaryRepresentationAsInteger($this->fromBinaryRepresentationAsInteger($old) + $data['value']));
            }
        }
        if ($loopCatcher <= 0) {
            throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
        }
    }

    /**
     * @param array<string> $metaData
     * @param string $labels
     * @return string
     */
    private function assembleLabelKey(array $metaData, string $labels): string
    {
        return implode(':', [ $this->prometheusPrefix, $metaData['type'], $metaData['name'], $labels, 'label' ]);
    }

    /**
     * Store ':label' keys for each metric's labelName in APCu.
     *
     * @param array<mixed> $data
     * @return void
     */
    private function storeLabelKeys(array $data): void
    {
        // Store labelValues in each labelName key
        foreach ($data['labelNames'] as $seq => $label) {
            $this->addItemToKey(implode(':', [
                $this->prometheusPrefix,
                $data['type'],
                $data['name'],
                $label,
                'label'
            ]), isset($data['labelValues']) ? $data['labelValues'][$seq] : ''); // may not need the isset check
        }
    }

    /**
     * Acquire mutex lock on $key.
     * We use a TTL so a stale lock will auto-clear if the PHP process dies while the lock exists in APC.
     * The same TTL as the metainfo cache is used: we don't want a stale lock hanging around way longer
     * than the cache. (If cache is disabled then our TTL is 1 second).
     *
     * This function will spin in a tight loop until it has acquired the lock, then will return. Or it will throw
     * a runtime exception if the lock was unable to be acquired. (This has happened in stress-testing when APC
     * filled up completely while adding Prometheus metrics, and apcu_add/apcu_cas() never succeeded.)
     *
     * @param string $key
     * @param int $apc_ttl
     * @return void
     * @throws RuntimeException
     */
    private function acquireLock(string $key, int $apc_ttl): void
    {
        $lock = $key . ':_lock';
        $loopCatcher = self::CAS_LOOP_RETRIES;
        $apc_ttl = max(1, $apc_ttl);
        $done = false;

        while (!$done && $loopCatcher-- > 0) {
            apcu_add($lock, 0, $apc_ttl);
            $done = apcu_cas($lock, 0, 1);
        }
        if ($loopCatcher <= 0) {
            apcu_delete($lock);
            throw new RuntimeException('Caught possible infinite loop in ' . __METHOD__ . '()');
        }
    }

    /**
     * Release mutex lock on $key, if it exists
     *
     * @return void
     */
    private function releaseLock(string $key): void
    {
        $lock = $key . ':_lock';
        apcu_delete($lock);
    }

    /**
     * Ensures an array serialized into APCu contains exactly one copy of a given string
     *
     * @return void
     * @throws RuntimeException
     */
    private function addItemToKey(string $key, string $item): void
    {
        $this->acquireLock($key, $this->metainfoCacheTTL);

        // Modify serialized array stored in $key
        $arr = apcu_fetch($key);
        if (false === $arr) {
            $arr = [];
        }
        if (!in_array($item, $arr, true)) {
            $arr[] = $item;
            apcu_store($key, $arr);
        }

        $this->releaseLock($key);
    }

    /**
     * @deprecated use replacement method wipeStorage from Adapter interface
     *
     * @return void
     */
    public function flushAPC(): void
    {
        $this->wipeStorage();
    }

    /**
     * Removes all previously stored data from apcu
     *
     * NOTE: This is non-atomic: while it's iterating APC, another thread could write a new Prometheus key that doesn't get erased.
     * In case this happens, we call scanAndBuildMetainfoCache before reading metainfo back: this will ensure "orphaned"
     * metainfo gets enumerated.
     *
     * @return void
     */
    public function wipeStorage(): void
    {
        //                   /      / | PCRE expresion boundary
        //                    ^       | match from first character only
        //                     %s:    | common prefix substitute with colon suffix
        //                        .+  | at least one additional character
        $matchAll = sprintf('/^%s:.+/', $this->prometheusPrefix);

        foreach (new APCUIterator($matchAll) as $key => $value) {
            apcu_delete($key);
        }
    }

    /**
     * Sets the metainfo cache TTL; how long to retain metainfo before scanning APC keyspace again (default 1 second)
     *
     * @param int $ttl
     * @return void
     */
    public function setMetainfoTTL(int $ttl) {
        $this->metainfoCacheTTL = $ttl;
    }

    /**
     * Scans the APC keyspace for all metainfo keys. A new metainfo cache array is built,
     * which references all metadata keys in APC at that moment. This prevents a corner-case
     * where an orphaned key, while remaining writable, is rendered permanently invisible when reading
     * or enumerating metrics.
     *
     * Writing the cache to APC allows it to be shared by other threads and by subsequent calls to getMetas(). This
     * reduces contention on APC from repeated scans, and provides about a 2.5x speed-up when calling $this->collect().
     * The cache TTL is very short (default: 1sec), so if new metrics are tracked after the cache is built, they will
     * be readable at most 1 second after being written.
     *
     * Setting $apc_ttl less than 1 will disable the cache.
     * 
     * @param int $apc_ttl
     * @return array
     */
    private function scanAndBuildMetainfoCache(int $apc_ttl): array
    {
        $arr = [];
        $matchAllMeta = sprintf('/^%s:.*:meta/', $this->prometheusPrefix);
        foreach (new APCUIterator($matchAllMeta) as $apc_record) {
            $arr[] = $apc_record['key'];
        }
        if ($apc_ttl >= 1) {
            apcu_store($this->metainfoCacheKey, $arr, $apc_ttl);
        }
        return $arr;
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function metaKey(array $data): string
    {
        return implode(':', [$this->prometheusPrefix, $data['type'], $data['name'], 'meta']);
    }

    /**
     * @param mixed[] $data
     * @return string
     */
    private function valueKey(array $data): string
    {
        return implode(':', [
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            'value',
        ]);
    }

    /**
     * @param mixed[] $data
     * @param string|int $bucket
     * @return string
     */
    private function histogramBucketValueKey(array $data, $bucket): string
    {
        return implode(':', [
            $this->prometheusPrefix,
            $data['type'],
            $data['name'],
            $this->encodeLabelValues($data['labelValues']),
            $bucket,
            'value',
        ]);
    }

    /**
     * @param mixed[] $data
     * @return mixed[]
     */
    private function metaData(array $data): array
    {
        $metricsMetaData = $data;
        unset($metricsMetaData['value'], $metricsMetaData['command'], $metricsMetaData['labelValues']);
        return $metricsMetaData;
    }

    /**
     * When given a ragged 2D array $labelValues of arbitrary size, and a 1D array $labelNames containing one
     * string labeling each row of $labelValues, return an array-of-arrays containing all possible permutations
     * of labelValues, with the sub-array elements in order of labelName.
     *
     * Example input:
     *  $labelNames:  ['endpoint', 'method', 'result']
     *  $labelValues: [0] => ['/', '/private', '/metrics'], // "endpoint"
     *                [1] => ['put', 'get', 'post'],        // "method"
     *                [2] => ['success', 'fail']            // "result"
     * Returned array:
     *  [0] => ['/', 'put', 'success'],          [1] => ['/', 'put', 'fail'],             [2] => ['/', 'get', 'success'],
     *  [3] => ['/', 'get', 'fail'],             [4] => ['/', 'post', 'success'],         [5] => ['/', 'post', 'fail'],
     *  [6] => ['/private', 'put', 'success'],   [7] => ['/private', 'put', 'fail'],      [8] => ['/private', 'get', 'success'],
     *  [9] => ['/private', 'get', 'fail'],     [10] => ['/private', 'post', 'success'], [11] => ['/private', 'post', 'fail'],
     *  [12] => ['/metrics', 'put', 'success'], [13] => ['/metrics', 'put', 'fail'],     [14] => ['/metrics', 'get', 'success'],
     *  [15] => ['/metrics', 'get', 'fail'],    [16] => ['/metrics', 'post', 'success'], [17] => ['/metrics', 'post', 'fail']
     * @param array<string> $labelNames
     * @param array<array> $labelValues
     * @return array<array>
     */
    private function buildPermutationTree(array $labelNames, array $labelValues): array
    {
        $treeRowCount = count(array_keys($labelNames));
        $numElements = 1;
        $treeInfo = [];
        for ($i = $treeRowCount - 1; $i >= 0; $i--) {
            $treeInfo[$i]['numInRow'] = count($labelValues[$i]);
            $numElements *= $treeInfo[$i]['numInRow'];
            $treeInfo[$i]['numInTree'] = $numElements;
        }

        $map = array_fill(0, $numElements, []);
        for ($row = 0; $row < $treeRowCount; $row++) {
            $col = $i = 0;
            while ($i < $numElements) {
                $val = $labelValues[$row][$col];
                $map[$i] = array_merge($map[$i], array($val));
                if (++$i % ($treeInfo[$row]['numInTree'] / $treeInfo[$row]['numInRow']) == 0) {
                    $col = ++$col % $treeInfo[$row]['numInRow'];
                }
            }
        }
        return $map;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectCounters(): array
    {
        $counters = [];
        foreach ($this->getMetas('counter') as $counter) {
            $metaData = json_decode($counter['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach ($this->getValues('counter', $metaData) as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->fromBinaryRepresentationAsInteger($value['value']),
                ];
            }
            $this->sortSamples($data['samples']);
            $counters[] = new MetricFamilySamples($data);
        }
        return $counters;
    }

    /**
     * When given a type ('histogram', 'gauge', or 'counter'), return an iterable array of matching records retrieved from APCu
     *
     * @param string $type
     * @return array<array>
     */
    private function getMetas(string $type): array
    {
        $arr = [];
        $metaCache = apcu_fetch($this->metainfoCacheKey);
        if (!is_array($metaCache)) {
            $metaCache = $this->scanAndBuildMetainfoCache($this->metainfoCacheTTL);
        }
        foreach ($metaCache as $metaKey) {
            if ((1 === preg_match('/' . $this->prometheusPrefix . ':' . $type . ':.*:meta/', $metaKey)) && false !== ($gauge = apcu_fetch($metaKey))) {
                $arr[] = [ 'key' => $metaKey, 'value' => $gauge ];
            }
        }
        return $arr;
    }

    /**
     * When given a type ('histogram', 'gauge', or 'counter') and metaData array, return an iterable array of matching records retrieved from APCu
     *
     * @param string $type
     * @param array<mixed> $metaData
     * @return array<array>
     */
    private function getValues(string $type, array $metaData): array
    {
        $labels = $arr = [];
        foreach (array_values($metaData['labelNames']) as $label) {
            $labelKey = $this->assembleLabelKey($metaData, $label);
            if (is_array($tmp = apcu_fetch($labelKey))) {
                $labels[] = $tmp;
            }
        }
        // Append the histogram bucket-list and the histogram-specific label 'sum' to labels[] then generate the permutations
        if (isset($metaData['buckets'])) {
            $metaData['buckets'][] = 'sum';
            $labels[] = $metaData['buckets'];
            $metaData['labelNames'][] = '__histogram_buckets';
        }
        $labelValuesList = $this->buildPermutationTree($metaData['labelNames'], $labels);
        unset($labels);
        $histogramBucket = '';
        foreach ($labelValuesList as $labelValues) {
            // Extract bucket value from permuted element, if present, then construct the key and retrieve
            if (isset($metaData['buckets'])) {
                $histogramBucket = ':' . array_pop($labelValues);
            }
            $key = $this->prometheusPrefix . ":{$type}:{$metaData['name']}:" . $this->encodeLabelValues($labelValues) . $histogramBucket . ':value';
            if (false !== ($value = apcu_fetch($key))) {
                $arr[] = [ 'key' => $key, 'value' => $value ];
            }
        }
        return $arr;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectGauges(): array
    {
        $gauges = [];
        foreach ($this->getMetas('gauge') as $gauge) {
            $metaData = json_decode($gauge['value'], true);
            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'samples' => [],
            ];
            foreach ($this->getValues('gauge', $metaData) as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $data['samples'][] = [
                    'name' => $metaData['name'],
                    'labelNames' => [],
                    'labelValues' => $this->decodeLabelValues($labelValues),
                    'value' => $this->fromBinaryRepresentationAsInteger($value['value']),
                ];
            }
            $this->sortSamples($data['samples']);
            $gauges[] = new MetricFamilySamples($data);
        }
        return $gauges;
    }

    /**
     * @return MetricFamilySamples[]
     */
    private function collectHistograms(): array
    {
        $histograms = [];
        foreach ($this->getMetas('histogram') as $histogram) {
            $metaData = json_decode($histogram['value'], true);

            // Add the Inf bucket so we can compute it later on
            $metaData['buckets'][] = '+Inf';

            $data = [
                'name' => $metaData['name'],
                'help' => $metaData['help'],
                'type' => $metaData['type'],
                'labelNames' => $metaData['labelNames'],
                'buckets' => $metaData['buckets'],
            ];

            $histogramBuckets = [];
            foreach ($this->getValues('histogram', $metaData) as $value) {
                $parts = explode(':', $value['key']);
                $labelValues = $parts[3];
                $bucket = $parts[4];
                // Key by labelValues
                $histogramBuckets[$labelValues][$bucket] = $value['value'];
            }

            // Compute all buckets
            $labels = array_keys($histogramBuckets);
            sort($labels);
            foreach ($labels as $labelValues) {
                $acc = 0;
                $decodedLabelValues = $this->decodeLabelValues($labelValues);
                foreach ($data['buckets'] as $bucket) {
                    $bucket = (string)$bucket;
                    if (!isset($histogramBuckets[$labelValues][$bucket])) {
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    } else {
                        $acc += $histogramBuckets[$labelValues][$bucket];
                        $data['samples'][] = [
                            'name' => $metaData['name'] . '_' . 'bucket',
                            'labelNames' => ['le'],
                            'labelValues' => array_merge($decodedLabelValues, [$bucket]),
                            'value' => $acc,
                        ];
                    }
                }

                // Add the count
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_count',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $acc,
                ];

                // Add the sum
                $data['samples'][] = [
                    'name' => $metaData['name'] . '_sum',
                    'labelNames' => [],
                    'labelValues' => $decodedLabelValues,
                    'value' => $this->fromBinaryRepresentationAsInteger($histogramBuckets[$labelValues]['sum']),
                ];
            }
            $histograms[] = new MetricFamilySamples($data);
        }
        return $histograms;
    }

    /**
     * @param mixed $val
     * @return int
     * @throws RuntimeException
     */
    private function toBinaryRepresentationAsInteger($val): int
    {
        $packedDouble = pack('d', $val);
        if ((bool)$packedDouble !== false) {
            $unpackedData = unpack("Q", $packedDouble);
            if (is_array($unpackedData)) {
                return $unpackedData[1];
            }
        }
        throw new RuntimeException("Formatting from binary representation to integer did not work");
    }

    /**
     * @param mixed $val
     * @return float
     * @throws RuntimeException
     */
    private function fromBinaryRepresentationAsInteger($val): float
    {
        $packedBinary = pack('Q', $val);
        if ((bool)$packedBinary !== false) {
            $unpackedData = unpack("d", $packedBinary);
            if (is_array($unpackedData)) {
                return $unpackedData[1];
            }
        }
        throw new RuntimeException("Formatting from integer to binary representation did not work");
    }

    /**
     * @param mixed[] $samples
     */
    private function sortSamples(array &$samples): void
    {
        usort($samples, function ($a, $b): int {
            return strcmp(implode("", $a['labelValues']), implode("", $b['labelValues']));
        });
    }

    /**
     * @param mixed[] $values
     * @return string
     * @throws RuntimeException
     */
    private function encodeLabelValues(array $values): string
    {
        $json = json_encode($values);
        if (false === $json) {
            throw new RuntimeException(json_last_error_msg());
        }
        return base64_encode($json);
    }

    /**
     * @param string $values
     * @return mixed[]
     * @throws RuntimeException
     */
    private function decodeLabelValues(string $values): array
    {
        $json = base64_decode($values, true);
        if (false === $json) {
            throw new RuntimeException('Cannot base64 decode label values');
        }
        $decodedValues = json_decode($json, true);
        if (false === $decodedValues) {
            throw new RuntimeException(json_last_error_msg());
        }
        return $decodedValues;
    }
}
