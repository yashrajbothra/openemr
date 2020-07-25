<?php

/**
 * ConditionService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Validators\BaseValidator;
use OpenEMR\Validators\ConditionValidator;
use OpenEMR\Validators\ProcessingResult;

class ConditionService extends BaseService
{
    private const CONDITION_TABLE = "lists";
    private const PATIENT_TABLE = "patient_data";
    private $uuidRegistery;
    private $conditionValidator;

    /**
     * Default constructor.
     */
    public function __construct()
    {
        parent::__construct('lists');
        $this->uuidRegistery = new UuidRegistry(['table_name' => self::CONDITION_TABLE]);
        $this->uuidRegistery->createMissingUuids();
        (new UuidRegistry(['table_name' => self::PATIENT_TABLE]))->createMissingUuids();
        $this->conditionValidator = new ConditionValidator();
    }

    /**
     * Returns a list of condition matching optional search criteria.
     * Search criteria is conveyed by array where key = field/column name, value = field value.
     * If no search criteria is provided, all records are returned.
     *
     * @param  $search search array parameters
     * @param  $isAndCondition specifies if AND condition is used for multiple criteria. Defaults to true.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getAll($search = array(), $isAndCondition = true)
    {
        // Validating and Converting Patient UUID to PID
        if (isset($search['lists.pid'])) {
            $isValidPatient = $this->conditionValidator->validateId(
                'uuid',
                self::PATIENT_TABLE,
                $search['lists.pid'],
                true
            );
            if ($isValidPatient !== true) {
                return $isValidPatient;
            }
            $puuidBytes = UuidRegistry::uuidToBytes($search['lists.pid']);
            $search['lists.pid'] = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        }

        // Validating and Converting UUID to ID
        if (isset($search['lists.id'])) {
            $isValidcondition = $this->conditionValidator->validateId(
                'uuid',
                self::CONDITION_TABLE,
                $search['lists.id'],
                true
            );
            if ($isValidcondition !== true) {
                return $isValidcondition;
            }
            $uuidBytes = UuidRegistry::uuidToBytes($search['lists.id']);
            $search['lists.id'] = $this->getIdByUuid($uuidBytes, self::CONDITION_TABLE, "id");
        }

        $sqlBindArray = array();
        $sql = "SELECT lists.*,
                        patient.uuid as puuid,
                        verification.title as verification_title
                        FROM lists
                        LEFT JOIN list_options as verification ON verification.option_id = lists.verification
                        RIGHT JOIN patient_data as patient ON patient.pid = lists.pid
                        WHERE lists.type = 'medical_problem'";

        if (!empty($search)) {
            $sql .= ' AND ';
            $whereClauses = array();
            foreach ($search as $fieldName => $fieldValue) {
                array_push($whereClauses, $fieldName . ' = ?');
                array_push($sqlBindArray, $fieldValue);
            }
            $sqlCondition = ($isAndCondition == true) ? 'AND' : 'OR';
            $sql .= implode(' ' . $sqlCondition . ' ', $whereClauses);
        }

        $statementResults = sqlStatement($sql, $sqlBindArray);

        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $row['uuid'] = UuidRegistry::uuidToString($row['uuid']);
            $row['puuid'] = UuidRegistry::uuidToString($row['puuid']);
            if ($row['diagnosis'] != "") {
                $row['diagnosis'] = $this->addDiagnosis($row['diagnosis']);
            }
            $processingResult->addData($row);
        }
        return $processingResult;
    }

    /**
     * Returns a single condition record by uuid.
     * @param $uuid - The condition uuid identifier in string format.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getOne($uuid)
    {
        $processingResult = new ProcessingResult();

        $isValid = BaseValidator::validateId("uuid", "lists", $uuid, true);

        if ($isValid !== true) {
            $validationMessages = [
                'uuid' => ["invalid or nonexisting value" => " value " . $uuid]
            ];
            $processingResult->setValidationMessages($validationMessages);
            return $processingResult;
        }

        $sql = "SELECT lists.*,
                        patient.uuid as puuid,
                        verification.title as verification_title
                        FROM lists
                        LEFT JOIN list_options as verification ON verification.option_id = lists.verification
                        RIGHT JOIN patient_data as patient ON patient.pid = lists.pid
                        WHERE lists.type = 'medical_problem' AND lists.uuid = ?";

        $uuidBinary = UuidRegistry::uuidToBytes($uuid);
        $sqlResult = sqlQuery($sql, [$uuidBinary]);
        $sqlResult['uuid'] = UuidRegistry::uuidToString($sqlResult['uuid']);
        $sqlResult['puuid'] = UuidRegistry::uuidToString($sqlResult['puuid']);
        if ($sqlResult['diagnosis'] != "") {
            $row['diagnosis'] = $this->addDiagnosis($sqlResult['diagnosis']);
        }
        $processingResult->addData($sqlResult);
        return $processingResult;
    }


    /**
     * Inserts a new condition record.
     *
     * @param $data The condition fields (array) to insert.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function insert($data)
    {
        $processingResult = $this->conditionValidator->validate(
            $data,
            ConditionValidator::DATABASE_INSERT_CONTEXT
        );

        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($data['puuid']);
        $data['pid'] = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $data['uuid'] = (new UuidRegistry(['table_name' => self::CONDITION_TABLE]))->createUuid();

        $query = $this->buildInsertColumns($data);
        $sql  = " INSERT INTO lists SET";
        $sql .= "     date=NOW(),";
        $sql .= "     activity=1,";
        $sql .= "     type='medical_problem',";
        $sql .= $query['set'];
        $results = sqlInsert(
            $sql,
            $query['bind']
        );

        if ($results) {
            $processingResult->addData(array(
                'id' => $results,
                'uuid' => UuidRegistry::uuidToString($data['uuid'])
            ));
        } else {
            $processingResult->addInternalError("error processing SQL Insert");
        }

        return $processingResult;
    }

    /**
     * Updates an existing condition record.
     *
     * @param $uuid - The condition uuid identifier in string format used for update.
     * @param $data - The updated condition data fields
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function update($uuid, $data)
    {
        if (empty($data)) {
            $processingResult = new ProcessingResult();
            $processingResult->setValidationMessages("Invalid Data");
            return $processingResult;
        }

        $data["uuid"] = $uuid;
        $processingResult = $this->conditionValidator->validate(
            $data,
            ConditionValidator::DATABASE_UPDATE_CONTEXT
        );
        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $query = $this->buildUpdateColumns($data);
        $sql = " UPDATE lists SET ";
        $sql .= $query['set'];
        $sql .= " WHERE `uuid` = ?";
        $sql .= "       AND `type` = 'medical_problem'";

        $uuidBinary = UuidRegistry::uuidToBytes($uuid);
        array_push($query['bind'], $uuidBinary);
        $sqlResult = sqlStatement($sql, $query['bind']);

        if (!$sqlResult) {
            $processingResult->addErrorMessage("error processing SQL Update");
        } else {
            $processingResult = $this->getOne($uuid);
        }
        return $processingResult;
    }

    /**
     * Deletes an existing condition record.
     *
     * @param $puuid - The patient uuid identifier in string format used for update.
     * @param $uuid - The condition uuid identifier in string format used for update.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function delete($puuid, $uuid)
    {
        $processingResult = new ProcessingResult();

        $isValid = $this->conditionValidator->validateId("uuid", "lists", $uuid, true);
        $isPatientValid = $this->conditionValidator->validateId("uuid", "patient_data", $puuid, true);

        if ($isValid !== true || $isPatientValid !== true) {
            $validationMessages = [
                'UUID' => ["invalid or nonexisting value"]
            ];
            $processingResult->setValidationMessages($validationMessages);
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($puuid);
        $auuid = UuidRegistry::uuidToBytes($uuid);
        $pid = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $sql  = "DELETE FROM lists WHERE pid=? AND uuid=? AND type='medical_problem'";

        $results = sqlStatement($sql, array($pid, $auuid));

        if ($results) {
            $processingResult->addData(array(
                'uuid' => $uuid
            ));
        } else {
            $processingResult->addInternalError("error processing SQL Insert");
        }

        return $processingResult;
    }
}
