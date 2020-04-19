<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\Extractor\Service\Extraction;

use Causal\Extractor\Utility\ExtensionHelper;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Index\ExtractorInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Class AbstractExtractionService
 *
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
abstract class AbstractExtractionService implements ExtractorInterface
{
    /**
     * @var array
     */
    protected $settings;

    /**
     * @var string
     * @abstract
     */
    protected $serviceName = null;

    /**
     * AbstractService constructor.
     */
    public function __construct()
    {
        if (version_compare(TYPO3_version, '9.0', '<')) {
            $this->settings = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['extractor'] ?? '') ?? [];
        } else {
            $this->settings = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['extractor'] ?? [];
        }
    }

    /**
     * @var array
     */
    protected $supportedFileExtensions = ['__INVALID__'];

    /**
     * @var array
     */
    protected $supportedFileTypes = [];

    /**
     * Priority in handling extraction.
     *
     * @var int
     */
    protected $priority = 1;

    /**
     * Returns an array of supported file types.
     *
     * An empty array indicates all file types.
     *
     * @return array
     */
    public function getFileTypeRestrictions()
    {
        return $this->supportedFileTypes;
    }

    /**
     * Returns an array of supported file extensions.
     *
     * @return array
     */
    public function getFileExtensionRestrictions()
    {
        return $this->supportedFileExtensions;
    }

    /**
     * Returns all supported DriverTypes.
     *
     * Since some processors may only work for local files, and other
     * are especially made for processing files from remote.
     *
     * Returns array of strings with driver names of Drivers which are supported,
     * If the driver did not register a name, it's the class name.
     * empty array indicates no restrictions.
     *
     * @return array
     */
    public function getDriverRestrictions()
    {
        return [
            'Local',
        ];
    }

    /**
     * Returns the data priority of the processing Service.
     *
     * Defines the precedence if several processors
     * can handle the same file.
     *
     * Should be between 1 and 100, 100 is more important than 1.
     *
     * @return integer
     */
    public function getPriority()
    {
        return max(1, min(100, $this->priority));
    }

    /**
     * Returns the execution priority of the extraction Service.
     *
     * Should be between 1 and 100, 100 means runs as first service, 1 runs at last service.
     *
     * @return integer
     */
    public function getExecutionPriority()
    {
        return $this->getPriority();
    }

    /**
     * Returns the potential mapping files.
     *
     * @param File $file
     * @param array $types
     * @return array
     */
    public function getPotentialMappingFiles(File $file, array &$types = null)
    {
        $potentialFiles = [];
        $types = $this->extensionToServiceTypes($file->getExtension());

        if (version_compare(TYPO3_version, '8.0', '>=')) {
            $pathConfiguration = is_dir($this->settings['mapping_base_directory'])
                ? $this->settings['mapping_base_directory']
                : GeneralUtility::getFileAbsFileName($this->settings['mapping_base_directory']);
        } else {
            $pathConfiguration = GeneralUtility::getFileAbsFileName($this->settings['mapping_base_directory'], false);
        }
        if ($pathConfiguration === '' || !is_dir($pathConfiguration)) {
            $pathConfiguration = ExtensionManagementUtility::extPath('extractor') . 'Configuration/Services/';
        }
        $pathConfiguration = rtrim($pathConfiguration, '/') . '/';

        // Always fall back to 'default'...
        $types[] = 'default';

        // ... and even keep compatibility with v1.0 and v1.1
        $types[] = 'metadata';

        foreach ($types as $type) {
            $potentialFiles[] = $pathConfiguration . $this->serviceName . '/' . $type . '.json';
        }

        static::getLogger()->debug(
            'Found potential mapping configuration files',
            [
                'file' => $file->getUid(),
                'identifier' => $file->getCombinedIdentifier(),
                'filenames' => $potentialFiles,
            ]
        );

        return $potentialFiles;
    }

    /**
     * Returns the data mapping for a given service key/subtype.
     *
     * @param File $file
     * @return array|null
     * @throws \Exception
     */
    protected function getDataMapping(File $file)
    {
        $types = [];
        $mappingFiles = $this->getPotentialMappingFiles($file, $types);
        $mappingFileName = null;

        foreach ($mappingFiles as $mappingFile) {
            if (is_file($mappingFile)) {
                $mappingFileName = $mappingFile;
                break;
            }
        }

        static::getLogger()->debug(
            'Mapping configuration file in use',
            [
                'file' => $file->getUid(),
                'identifier' => $file->getCombinedIdentifier(),
                'filename' => $mappingFileName,
            ]
        );

        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extractor']['dataMappingHook'])) {
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['extractor']['dataMappingHook'] as $classRef) {
                $hookObject = GeneralUtility::getUserObj($classRef);
                if (!method_exists($hookObject, 'postProcessDataMapping')) {
                    throw new \Exception($classRef . ' must provide a method "postProcessDataMapping', 1425290629);
                }
                $hookData = [
                    'service' => $this->serviceName,
                    'types' => $types,
                    'mappingFileName' => $mappingFileName,
                ];
                $newMappingFileName = $hookObject->postProcessDataMapping($hookData, $this);
                if ($newMappingFileName !== null) {
                    $mappingFileName = $newMappingFileName;
                }
            }
        }

        static::getLogger()->debug(
            'Mapping configuration file in use after hook',
            [
                'filename' => $mappingFileName,
            ]
        );

        // Safeguard
        if (!is_file($mappingFileName)) {
            static::getLogger()->warning('Not considering non-existent mapping configuration file', ['filename' => $mappingFileName]);
            return null;
        }

        $dataMapping = json_decode(file_get_contents($mappingFileName), true);
        if (!is_array($dataMapping)) {
            static::getLogger()->warning('Cannot decode JSON from mapping configuration file', ['filename' => $mappingFileName]);
            return null;
        }

        return $dataMapping;
    }

    /**
     * Remaps $data coming from a service to a FAL-compliant array.
     *
     * @param array $data
     * @param array $mapping
     * @return array
     */
    protected function remapServiceOutput(array $data, array $mapping = null)
    {
        $output = [];

        if (!is_array($mapping)) {
            // Make sure unprocessed arrays will never have a risk to find their way to the database
            foreach ($data as $key => $value) {
                if (!is_array($value)) {
                    $output[$key] = $value;
                }
            }
            return $output;
        }

        foreach ($mapping as $m) {
            $falKey = $m['FAL'];
            $alternativeKeys = $m['DATA'];
            if (!is_array($alternativeKeys)) {
                $alternativeKeys = [$alternativeKeys];
            }

            $value = null;
            foreach ($alternativeKeys as $dataKey) {
                list($compoundKey, $processor) = explode('->', $dataKey);
                $keys = explode('|', $compoundKey);
                $parentValue = null;
                $value = $data;
                foreach ($keys as $key) {
                    if (substr($key, 0, 7) === 'static:') {
                        $value = substr($key, 7);
                    } else {
                        $parentValue = $value;
                        $value = isset($value[$key]) ? $value[$key] : null;
                    }
                    if ($value === null) {
                        break;
                    }
                }
                if (isset($processor)) {
                    if (preg_match('/^([^(]+)(\((.*)\))?$/', $processor, $matches)) {
                        $processor = $matches[1];
                        $parameters = [$value];
                        if (isset($matches[3])) {
                            if (substr($matches[3], 0, 1) === '\'') {
                                $parameters[] = substr($matches[3], 1, -1);
                            } else {
                                $fields = GeneralUtility::trimExplode(',', $matches[3]);
                                foreach ($fields as $field) {
                                    $parameters[] = $parentValue[$field];
                                }
                            }
                        }
                        $value = call_user_func_array($processor, $parameters);
                    }
                }
                if ($value !== null) {
                    // Do not try any further alternative key, we have a value
                    break;
                }
            }

            if ($value !== null && $value !== '') {
                $output[$falKey] = $value;
            }
        }

        foreach ($output as $key => $value) {
            // Known cases: "keywords", "alternative", "SupplementalCategories"
            if (is_array($value)) {
                $output[$key] = implode(', ', $value);
            }
        }

        static::getLogger()->debug(
            'Raw data remapped to FAL array',
            [
                'input' => $data,
                'output' => $output,
            ]
        );

        return $output;
    }

    /**
     * Processes the categories.
     *
     * @param File $file
     * @param array &$metadata
     * @return void
     */
    protected function processCategories(File $file, array &$metadata)
    {
        $categories = [];
        $key = '__categories__';
        if (isset($metadata[$key])) {
            $categories = GeneralUtility::trimExplode(',', $metadata[$key], true);
            unset($metadata[$key]);
        }
        if (empty($categories) || $file->getUid() === 0) {
            return;
        }

        // Fetch the uid associated to the corresponding sys_file_metadata record
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_metadata')
            ->select(
                ['uid'],
                'sys_file_metadata',
                [
                    'file' => $file->getUid(),
                    'sys_language_uid' => 0,
                ]
            )
            ->fetch();
        if (!$row) {
            // An error occurred, cannot proceed!
            return;
        }
        $fileMetadataUid = $row['uid'];

        $typo3Categories = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_category')
            ->select(
                ['uid', 'title'],
                'sys_category',
                [
                    'sys_language_uid' => 0,
                ]
            )
            ->fetchAll();

        // Remove currently associated categories for this file
        $relationTable = 'sys_category_record_mm';
        $tableConnection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($relationTable);
        $tableConnection->delete(
            $relationTable,
            [
                'uid_foreign' => $fileMetadataUid,
                'tablenames' => 'sys_file_metadata',
                'fieldname' => 'categories',
            ]
        );

        $sorting = 1;
        $data = [];
        foreach ($categories as $category) {
            foreach ($typo3Categories as $typo3Category) {
                if ($typo3Category['title'] === $category) {
                    $data[] = [
                        'uid_local' => (int)$typo3Category['uid'],
                        'uid_foreign' => $fileMetadataUid,
                        'tablenames' => 'sys_file_metadata',
                        'fieldname' => 'categories',
                        'sorting_foreign' => $sorting++,
                    ];
                }
            }
        }

        if (!empty($data)) {
            $tableConnection->bulkInsert(
                $relationTable,
                $data,
                ['uid_local', 'uid_foreign', 'tablenames', 'fieldname', 'sorting_foreign']
            );
        }
    }

    /**
     * Maps a file extension to possible service types.
     *
     * @param string $extension
     * @return string[]
     */
    protected function extensionToServiceTypes($extension)
    {
        // Normalize the extension
        $extension = strtolower($extension);
        switch ($extension) {
            case 'aif':
                $extension = 'aiff';
                break;
            case 'htm':
            case 'phtml':
                $extension = 'html';
                break;
            case 'jpeg':
                $extension = 'jpg';
                break;
            case 'midi':
                $extension = 'mid';
                break;
            case 'mpeg':
                $extension = 'mpg';
                break;
            case 'tiff':
                $extension = 'tif';
                break;
        }

        $types = [];

        // Most-specific service type first
        $types[] = $extension;

        // Then category of file
        $types[] = ExtensionHelper::getExtensionCategory($extension);

        return $types;
    }

    /**
     * Returns a logger.
     *
     * @return \TYPO3\CMS\Core\Log\Logger
     */
    abstract protected static function getLogger();
}
