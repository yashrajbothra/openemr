<?php

/**
 * DocumentService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

require_once(dirname(__FILE__) . "/../../controllers/C_Document.class.php");

use Document;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Services\Search\SearchFieldException;
use OpenEMR\Validators\ProcessingResult;

class DocumentService extends BaseService
{
    const TABLE_NAME = "documents";

    public function __construct()
    {
        parent::__construct(self::TABLE_NAME);
        UuidRegistry::createMissingUuidsForTables([self::TABLE_NAME]);
    }

    public function isValidPath($path)
    {
        $docPathParts = explode("/", $path);

        unset($docPathParts[0]);

        $categoriesSql  = "  SELECT parent, id";
        $categoriesSql .= "    FROM categories";
        $categoriesSql .= "    WHERE replace(LOWER(name), ' ', '') = ?";

        $lastParent = null;
        $isValidPath = true;
        foreach ($docPathParts as $index => $part) {
            $categoryResults = sqlQuery($categoriesSql, str_replace("_", "", $part));

            if ($index === 1) {
                $lastParent = $categoryResults["id"];
                continue;
            }

            if ($categoryResults["parent"] === $lastParent) {
                $lastParent = $categoryResults["id"];
            } else {
                $isValidPath = false;
                break;
            }
        }

        return $isValidPath;
    }

    public function getLastIdOfPath($path)
    {
        $docPathParts = explode("/", $path);
        $lastInPath = end($docPathParts);

        $sql  = "  SELECT id";
        $sql .= "    FROM categories";
        $sql .= "    WHERE replace(LOWER(name), ' ', '') = ?";

        $results = sqlQuery($sql, str_replace("_", "", $lastInPath));
        return $results['id'];
    }

    public function getAllAtPath($pid, $path)
    {
        if (!$this->isValidPath($path)) {
            return false;
        }

        $categoryId = $this->getLastIdOfPath($path);

        $documentsSql  = " SELECT doc.url, doc.id, doc.mimetype, doc.docdate";
        $documentsSql .= " FROM documents doc";
        $documentsSql .= " JOIN categories_to_documents ctd on ctd.document_id = doc.id";
        $documentsSql .= " WHERE ctd.category_id = ? and doc.foreign_id = ? and doc.deleted = 0";

        $documentResults = sqlStatement($documentsSql, array($categoryId, $pid));

        $fileResults = array();
        while ($row = sqlFetchArray($documentResults)) {
            array_push($fileResults, array(
                "filename" => basename($row["url"]),
                "id" =>  $row["id"],
                "mimetype" =>  $row["mimetype"],
                "docdate" =>  $row["docdate"]
            ));
        }
        return $fileResults;
    }

    public function insertAtPath($pid, $path, $fileData)
    {
        // Ensure filetype is allowed
        if ($GLOBALS['secure_upload'] && !isWhiteFile($fileData["tmp_name"])) {
            error_log("OpenEMR API Error: Attempt to upload unsecure patient document was declined");
            return false;
        }

        // Ensure category exists
        if (!$this->isValidPath($path)) {
            error_log("OpenEMR API Error: Attempt to upload patient document to category that did not exist was declined");
            return false;
        }

        // Collect category id
        $categoryId = $this->getLastIdOfPath($path);

        // Store file in variable
        $file = file_get_contents($fileData["tmp_name"]);
        if (empty($file)) {
            error_log("OpenEMR API Error: Patient document was empty, so declined request");
            return false;
        }

        // Store the document in OpenEMR
        $doc = new \Document();
        $ret = $doc->createDocument($pid, $categoryId, $fileData["name"], mime_content_type($fileData["tmp_name"]), $file);
        if (!empty($ret)) {
            error_log("OpenEMR API Error: There was an error in attempt to upload a patient document");
            return false;
        }

        return true;
    }

    public function getFile($pid, $did)
    {
        $filenameSql = sqlQuery("SELECT `url`, `mimetype` FROM `documents` WHERE `id` = ? AND `foreign_id` = ? AND `deleted` = 0", [$did, $pid]);

        if (empty(basename($filenameSql['url']))) {
            $filename = "unknownName";
        } else {
            $filename = basename($filenameSql['url']);
        }

        $obj = new \C_Document();
        $document = $obj->retrieve_action($pid, $did, true, true, true);
        if (empty($document)) {
            error_log("OpenEMR API Error: Requested patient document was empty, so declined request");
            return false;
        }

        return ['filename' => $filename, 'mimetype' => $filenameSql['mimetype'], 'file' => $document];
    }

    public function search($search, $isAndCondition = true)
    {
        $processingResult = new ProcessingResult();

        try {
            $sql = "
            SELECT
                docs.id
                ,docs.uuid
                ,docs.url
                ,docs.mimetype
                ,docs.foreign_id
                ,docs.encounter_id
                ,docs.foreign_reference_id
                ,docs.foreign_reference_table
                ,docs.deleted
                ,docs.drive_uuid
                ,docs.`date`
                ,patients.puuid
                ,encounters.euuid
                ,users.user_uuid
                ,users.user_username
                ,users.user_npi
                ,users.user_physician_type

            FROM
                documents docs
            LEFT JOIN (
                select
                    encounter AS eid
                    ,uuid AS euuid
                    ,`date` AS encounter_date
                FROM
                    form_encounter
            ) encounters ON docs.encounter_id = encounters.eid
            LEFT JOIN
            (
                SELECT
                    uuid AS puuid
                    ,pid
                    FROM patient_data
            ) patients ON docs.foreign_id = patients.pid
            LEFT JOIN
            (
                SELECT
                    uuid AS user_uuid
                    ,username AS user_username
                    ,id AS user_id
                    ,npi AS user_npi
                    ,physician_type AS user_physician_type
                    FROM
                        users
            ) users ON docs.`owner` = users.user_id
        ";
            $whereClause = FhirSearchWhereClauseBuilder::build($search, $isAndCondition);

            $sql .= $whereClause->getFragment();
            $sqlBindArray = $whereClause->getBoundValues();

            $statementResults =  QueryUtils::sqlStatementThrowException($sql, $sqlBindArray);
            while ($row = sqlFetchArray($statementResults)) {
                $resultRecord = $this->createResultRecordFromDatabaseResult($row);
                $processingResult->addData($resultRecord);
            }
        } catch (SearchFieldException $exception) {
            $processingResult->setValidationMessages([$exception->getField() => $exception->getMessage()]);
        }
        return $processingResult;
    }

    public function getUuidFields(): array
    {
        return [ 'uuid', 'user_uuid', 'drive_uuid', 'puuid', 'euuid'];
    }
}
