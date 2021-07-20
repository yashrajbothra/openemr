<?php

/**
 * PrescriptionService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Validators\ProcessingResult;
use OpenEMR\Validators\BaseValidator;
use OpenEMR\Validators\PatientValidator;

class PrescriptionService extends BaseService
{
    private const DRUGS_TABLE = "drugs";
    private const PRESCRIPTION_TABLE = "prescriptions";
    private const PATIENT_TABLE = "patient_data";
    private const ENCOUNTER_TABLE = "form_encounter";
    private const PRACTITIONER_TABLE = "users";
    private $patientValidator;

    /**
     * Default constructor.
     */
    public function __construct()
    {
        parent::__construct(self::PRESCRIPTION_TABLE);
        UuidRegistry::createMissingUuidsForTables([self::PRESCRIPTION_TABLE, self::PATIENT_TABLE, self::ENCOUNTER_TABLE,
            self::PRACTITIONER_TABLE, self::DRUGS_TABLE]);
        $this->patientValidator = new PatientValidator();
    }

    /**
     * Returns a list of prescription matching optional search criteria.
     * Search criteria is conveyed by array where key = field/column name, value = field value.
     * If no search criteria is provided, all records are returned.
     *
     * @param  $search search array parameters
     * @param  $isAndCondition specifies if AND condition is used for multiple criteria. Defaults to true.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getAll($search = array(), $isAndCondition = true, $puuidBind = null)
    {
        $sqlBindArray = array();

        if (isset($search['patient.uuid'])) {
            $isValidPatient = $this->patientValidator->validateId(
                'uuid',
                self::PATIENT_TABLE,
                $search['patient.uuid'],
                true
            );
            if ($isValidPatient != true) {
                return $isValidPatient;
            }
            $search['patient.uuid'] = UuidRegistry::uuidToBytes($search['patient.uuid']);
        }

        if (!empty($puuidBind)) {
            // code to support patient binding
            $isValidPatient = $this->patientValidator->validateId(
                'uuid',
                self::PATIENT_TABLE,
                $puuidBind,
                true
            );
            if ($isValidPatient != true) {
                return $isValidPatient;
            }
        }

        $sql = "SELECT prescriptions.*,
                patient.uuid AS puuid,
                encounter.uuid AS euuid,
                practitioner.uuid AS pruuid,
                drug.uuid AS drug_uuid
                FROM prescriptions
                LEFT JOIN patient_data AS patient
                ON patient.pid = prescriptions.patient_id
                LEFT JOIN form_encounter AS encounter
                ON encounter.encounter = prescriptions.encounter
                LEFT JOIN users AS practitioner
                ON practitioner.id = prescriptions.provider_id
                LEFT JOIN drugs AS drug
                ON drug.drug_id = prescriptions.drug_id";

        if (!empty($search)) {
            $sql .= " WHERE ";
            if (!empty($puuidBind)) {
                // code to support patient binding
                $sql .= '(';
            }
            $whereClauses = array();
            $wildcardFields = array();
            foreach ($search as $fieldName => $fieldValue) {
                // support wildcard match on specific fields
                if (in_array($fieldName, $wildcardFields)) {
                    array_push($whereClauses, $fieldName . ' LIKE ?');
                    array_push($sqlBindArray, '%' . $fieldValue . '%');
                } else {
                    // equality match
                    array_push($whereClauses, $fieldName . ' = ?');
                    array_push($sqlBindArray, $fieldValue);
                }
            }
            $sqlCondition = ($isAndCondition == true) ? 'AND' : 'OR';
            $sql .= implode(' ' . $sqlCondition . ' ', $whereClauses);
            if (!empty($puuidBind)) {
                // code to support patient binding
                $sql .= ") AND `patient`.`uuid` = ?";
                $sqlBindArray[] = UuidRegistry::uuidToBytes($puuidBind);
            }
        } elseif (!empty($puuidBind)) {
            // code to support patient binding
            $sql .= " WHERE `patient`.`uuid` = ?";
            $sqlBindArray[] = UuidRegistry::uuidToBytes($puuidBind);
        }

        $statementResults = sqlStatement($sql, $sqlBindArray);
        $processingResult = new ProcessingResult();
        while ($row = sqlFetchArray($statementResults)) {
            $row['uuid'] = UuidRegistry::uuidToString($row['uuid']);
            $row['euuid'] = $row['euuid'] != null ? UuidRegistry::uuidToString($row['euuid']) : $row['euuid'];
            $row['puuid'] = UuidRegistry::uuidToString($row['puuid']);
            $row['pruuid'] = UuidRegistry::uuidToString($row['pruuid']);
            $row['drug_uuid'] = UuidRegistry::uuidToString($row['drug_uuid']);
            if ($row['rxnorm_drugcode'] != "") {
                $row['rxnorm_drugcode'] = $this->addCoding($row['rxnorm_drugcode']);
            }
            $processingResult->addData($row);
        }

        return $processingResult;
    }

    /**
     * Returns a single prescription record by id.
     * @param $uuid - The prescription uuid identifier in string format.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getOne($uuid, $puuidBind = null)
    {
        $processingResult = new ProcessingResult();

        $isValid = BaseValidator::validateId("uuid", self::PRESCRIPTION_TABLE, $uuid, true);

        if ($isValid !== true) {
            $validationMessages = [
                'uuid' => ["invalid or nonexisting value" => " value " . $uuid]
            ];
            $processingResult->setValidationMessages($validationMessages);
            return $processingResult;
        }

        if (!empty($puuidBind)) {
            // code to support patient binding
            $isValid = BaseValidator::validateId("uuid", self::PATIENT_TABLE, $puuidBind, true);
            if ($isValid !== true) {
                $validationMessages = [
                    'puuid' => ["invalid or nonexisting value" => " value " . $puuidBind]
                ];
                $processingResult->setValidationMessages($validationMessages);
                return $processingResult;
            }
        }

        $sql = "SELECT prescriptions.*,
                patient.uuid AS puuid,
                encounter.uuid AS euuid,
                practitioner.uuid AS pruuid,
                drug.uuid AS drug_uuid
                FROM prescriptions
                LEFT JOIN patient_data AS patient
                ON patient.pid = prescriptions.patient_id
                LEFT JOIN form_encounter AS encounter
                ON encounter.encounter = prescriptions.encounter
                LEFT JOIN users AS practitioner
                ON practitioner.id = prescriptions.provider_id
                LEFT JOIN drugs AS drug
                ON drug.drug_id = prescriptions.drug_id
                WHERE prescriptions.uuid = ?";

        $uuidBinary = UuidRegistry::uuidToBytes($uuid);
        $sqlBindArray = [$uuidBinary];

        if (!empty($puuidBind)) {
            // code to support patient binding
            $sql .= " AND `patient`.`uuid` = ?";
            $sqlBindArray[] = UuidRegistry::uuidToBytes($puuidBind);
        }

        $sqlResult = sqlQuery($sql, $sqlBindArray);
        if (!empty($sqlResult)) {
            $sqlResult['uuid'] = UuidRegistry::uuidToString($sqlResult['uuid']);
            $sqlResult['puuid'] = UuidRegistry::uuidToString($sqlResult['puuid']);
            $sqlResult['euuid'] = $sqlResult['euuid'] != null ? UuidRegistry::uuidToString($sqlResult['euuid']) : $sqlResult['euuid'];
            $sqlResult['pruuid'] = UuidRegistry::uuidToString($sqlResult['pruuid']);
            $sqlResult['drug_uuid'] = UuidRegistry::uuidToString($sqlResult['drug_uuid']);
            if ($sqlResult['rxnorm_drugcode'] != "") {
                $sqlResult['rxnorm_drugcode'] = $this->addCoding($sqlResult['rxnorm_drugcode']);
            }
            $processingResult->addData($sqlResult);
        }
        return $processingResult;
    }
}
