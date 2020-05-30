<?php

/**
 * EncounterService
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Services\FacilityService;
use OpenEMR\Validators\EncounterValidator;
use OpenEMR\Validators\ProcessingResult;
use Particle\Validator\Validator;

require_once dirname(__FILE__) . "/../../library/forms.inc";
require_once dirname(__FILE__) . "/../../library/encounter.inc";

class EncounterService extends BaseService
{
    private $encounterValidator;
    private $uuidRegistery;
    private const ENCOUNTER_TABLE = "form_encounter";
    private const PATIENT_TABLE = "patient_data";

    /**
     * Default constructor.
     */
    public function __construct()
    {
        parent::__construct('form_encounter');
        $this->uuidRegistery = new UuidRegistry(['table_name' => self::ENCOUNTER_TABLE]);
        $this->uuidRegistery->createMissingUuids();
        $this->encounterValidator = new EncounterValidator();
    }

    /**
     * Returns a single encounter record by encounter uuid and patient uuid.
     * @param $puuid - The patient identifier of particular encounter
     * @param $euuid - The encounter identifier used to lookup the encounter record.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getEncounterForPatient($puuid, $euuid)
    {

        $processingResult = new ProcessingResult();

        $isValidPatient = $this->encounterValidator->validateId('uuid', self::PATIENT_TABLE, $puuid, true);
        if (is_array($isValidPatient)) {
            $processingResult->setValidationMessages($isValidPatient);
            return $processingResult;
        }
        $puuidBytes = UuidRegistry::uuidToBytes($puuid);

        $pid = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        if (is_array($pid)) {
            $processingResult->setValidationMessages($pid);
            return $processingResult;
        }

        $isValidEncounter = $this->encounterValidator->validateId('uuid', self::ENCOUNTER_TABLE, $euuid, true);
        if (is_array($isValidEncounter)) {
            $processingResult->setValidationMessages($isValidEncounter);
            return $processingResult;
        }
        $euuidBytes = UuidRegistry::uuidToBytes($euuid);

        $sql = "SELECT fe.encounter as id,
                       fe.uuid as uuid,
                       fe.date,
                       fe.reason,
                       fe.facility,
                       fe.facility_id,
                       fe.pid,
                       fe.onset_date,
                       fe.sensitivity,
                       fe.billing_note,
                       fe.pc_catid,
                       fe.last_level_billed,
                       fe.last_level_closed,
                       fe.last_stmt_date,
                       fe.stmt_count,
                       fe.provider_id,
                       fe.supervisor_id,
                       fe.invoice_refno,
                       fe.referral_source,
                       fe.billing_facility,
                       fe.external_id,
                       fe.pos_code,
                       opc.pc_catname,
                       fa.name AS billing_facility_name
                       FROM form_encounter as fe
                       LEFT JOIN openemr_postcalendar_categories as opc
                       ON opc.pc_catid = fe.pc_catid
                       LEFT JOIN facility as fa ON fa.id = fe.billing_facility
                       WHERE pid=? and fe.uuid=?
                       ORDER BY fe.id
                       DESC";

        $sqlResult = sqlQuery($sql, array($pid, $euuidBytes));
        $sqlResult['uuid'] = UuidRegistry::uuidToString($sqlResult['uuid']);
        $processingResult->addData($sqlResult);
        return $processingResult;
    }

    /**
     * Returns a list of encounters matching the patient indentifier.
     *
     * @param  $puuid The patient identifier of particular encounter
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getEncountersForPatient($puuid)
    {
        $processingResult = new ProcessingResult();

        $isValidPatient = $this->encounterValidator->validateId('uuid', self::PATIENT_TABLE, $puuid, true);
        if (is_array($isValidPatient)) {
            $processingResult->setValidationMessages($isValidPatient);
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($puuid);
        $pid = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        if (is_array($pid)) {
            $processingResult->setValidationMessages($pid);
            return $processingResult;
        }

        $sql = "SELECT fe.encounter as id,
                       fe.uuid as uuid,
                       fe.date,
                       fe.reason,
                       fe.facility,
                       fe.facility_id,
                       fe.pid,
                       fe.onset_date,
                       fe.sensitivity,
                       fe.billing_note,
                       fe.pc_catid,
                       fe.last_level_billed,
                       fe.last_level_closed,
                       fe.last_stmt_date,
                       fe.stmt_count,
                       fe.provider_id,
                       fe.supervisor_id,
                       fe.invoice_refno,
                       fe.referral_source,
                       fe.billing_facility,
                       fe.external_id,
                       fe.pos_code,
                       opc.pc_catname,
                       fa.name AS billing_facility_name
                       FROM form_encounter as fe
                       LEFT JOIN openemr_postcalendar_categories as opc
                       ON opc.pc_catid = fe.pc_catid
                       LEFT JOIN facility as fa ON fa.id = fe.billing_facility
                       WHERE pid=?
                       ORDER BY fe.id
                       DESC";

        $statementResults = sqlStatement($sql, array($pid));
        while ($row = sqlFetchArray($statementResults)) {
            $row['uuid'] = UuidRegistry::uuidToString($row['uuid']);
            $processingResult->addData($row);
        }

        return $processingResult;
    }

    public function getEncounter($eid)
    {
        $sql = "SELECT fe.encounter as id,
                       fe.date,
                       fe.reason,
                       fe.facility,
                       fe.facility_id,
                       fe.pid,
                       fe.onset_date,
                       fe.sensitivity,
                       fe.billing_note,
                       fe.pc_catid,
                       fe.last_level_billed,
                       fe.last_level_closed,
                       fe.last_stmt_date,
                       fe.stmt_count,
                       fe.provider_id,
                       fe.supervisor_id,
                       fe.invoice_refno,
                       fe.referral_source,
                       fe.billing_facility,
                       fe.external_id,
                       fe.pos_code,
                       opc.pc_catname,
                       fa.name AS billing_facility_name
                       FROM form_encounter as fe
                       LEFT JOIN openemr_postcalendar_categories as opc
                       ON opc.pc_catid = fe.pc_catid
                       LEFT JOIN facility as fa ON fa.id = fe.billing_facility
                       WHERE fe.encounter=?
                       ORDER BY fe.id
                       DESC";

        return sqlQuery($sql, array($eid));
    }

    /**
     * Returns a list of encounters matching optional search criteria.
     * Search criteria is conveyed by array where key = field/column name, value = field value.
     * If no search criteria is provided, all records are returned.
     *
     * @param  $search search array parameters
     * @param  $isAndCondition specifies if AND condition is used for multiple criteria. Defaults to true.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getEncountersBySearch($search = array(), $isAndCondition = true)
    {
        $sql = "SELECT fe.encounter as id,
                       fe.date,
                       fe.reason,
                       fe.facility,
                       fe.facility_id,
                       fe.pid,
                       fe.onset_date,
                       fe.sensitivity,
                       fe.billing_note,
                       fe.pc_catid,
                       fe.last_level_billed,
                       fe.last_level_closed,
                       fe.last_stmt_date,
                       fe.stmt_count,
                       fe.provider_id,
                       fe.supervisor_id,
                       fe.invoice_refno,
                       fe.referral_source,
                       fe.billing_facility,
                       fe.external_id,
                       fe.pos_code,
                       opc.pc_catname,
                       fa.name AS billing_facility_name
                       FROM form_encounter as fe
                       LEFT JOIN openemr_postcalendar_categories as opc
                       ON opc.pc_catid = fe.pc_catid
                       LEFT JOIN facility as fa ON fa.id = fe.billing_facility
                       WHERE pid=? and fe.encounter=?
                       ORDER BY fe.id
                       DESC";

        return sqlQuery($sql, array($pid, $eid));
    }

    /**
     * Inserts a new Encounter record.
     *
     * @param $puuid The patient identifier of particular encounter
     * @param $data The encounter fields (array) to insert.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function insertEncounter($puuid, $data)
    {
        $processingResult = new ProcessingResult();

        $isValidPatient = $this->encounterValidator->validateId('uuid', self::PATIENT_TABLE, $puuid, true);
        if (is_array($isValidPatient)) {
            $processingResult->setValidationMessages($isValidPatient);
            return $processingResult;
        }

        $encounter = generate_id();
        $data['encounter'] = $encounter;
        $data['uuid'] = $this->uuidRegistery->createUuid();
        $data['date'] = date("Y-m-d");
        $puuidBytes = UuidRegistry::uuidToBytes($puuid);
        $data['pid'] = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $processingResult = $this->encounterValidator->validate($data, EncounterValidator::DATABASE_INSERT_CONTEXT);

        if ($processingResult->isValid()) {
            $query = $this->buildInsertColumns($data);
            $sql = " INSERT INTO form_encounter SET ";
            $sql .= $query['set'];

            $results = sqlInsert(
                $sql,
                $query['bind']
            );

            addForm(
                $encounter,
                "New Patient Encounter",
                $results,
                "newpatient",
                $data['pid'],
                $data["provider_id"],
                $data["date"]
            );

            if ($results) {
                $processingResult->addData(array(
                    'encounter' => $encounter,
                    'uuid' => UuidRegistry::uuidToString($data['uuid']),
                ));
            } else {
                $processingResult->addProcessingError("error processing SQL Insert");
            }
        }

        return $processingResult;
    }

    /**
     * Updates an existing Encounter record.
     *
     * @param $puuid The patient identifier of particular encounter.
     * @param $euuid - The Encounter identifier used for update.
     * @param $data - The updated Encounter data fields
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function updateEncounter($puuid, $euuid, $data)
    {
        $processingResult = new ProcessingResult();

        $isValidPatient = $this->encounterValidator->validateId('uuid', self::PATIENT_TABLE, $puuid, true);
        if (is_array($isValidPatient)) {
            $processingResult->setValidationMessages($isValidPatient);
            return $processingResult;
        }
        $isValidEncounter = $this->encounterValidator->validateId('uuid', self::ENCOUNTER_TABLE, $euuid, true);
        if (is_array($isValidEncounter)) {
            $processingResult->setValidationMessages($isValidEncounter);
            return $processingResult;
        }

        $puuidBytes = UuidRegistry::uuidToBytes($puuid);
        $euuidBytes = UuidRegistry::uuidToBytes($euuid);
        $data['pid'] = $this->getIdByUuid($puuidBytes, self::PATIENT_TABLE, "pid");
        $data['encounter'] = $this->getIdByUuid($euuidBytes, self::ENCOUNTER_TABLE, "encounter");

        $facilityService = new FacilityService();
        $facilityresult = $facilityService->getById($data["facility_id"]);
        $facility = $facilityresult['name'];
        $result = sqlQuery("SELECT sensitivity FROM form_encounter WHERE encounter = ?", array($data['encounter']));
        if ($result['sensitivity'] && !AclMain::aclCheckCore('sensitivities', $result['sensitivity'])) {
            return "You are not authorized to see this encounter.";
        }

        // See view.php to allow or disallow updates of the encounter date.
        if (!AclMain::aclCheckCore('encounters', 'date_a')) {
            unset($data["date"]);
        }

        $data['facility'] = $facility;
        $processingResult = $this->encounterValidator->validate($data, EncounterValidator::DATABASE_UPDATE_CONTEXT);

        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $query = $this->buildUpdateColumns($data);
        $sql = " UPDATE form_encounter SET ";
        $sql .= $query['set'];
        $sql .= " WHERE encounter = ?";
        $sql .= " AND pid = ?";

        array_push($query['bind'], $data['encounter']);
        array_push($query['bind'], $data['pid']);
        $results = sqlStatement(
            $sql,
            $query['bind']
        );

        if ($results) {
            $processingResult = $this->getEncounterForPatient($puuid, $euuid);
        } else {
            $processingResult->addProcessingError("error processing SQL Update");
        }

        return $processingResult;
    }

    public function insertSoapNote($pid, $eid, $data)
    {
        $soapSql  = " INSERT INTO form_soap SET";
        $soapSql .= "     date=NOW(),";
        $soapSql .= "     activity=1,";
        $soapSql .= "     pid=?,";
        $soapSql .= "     subjective=?,";
        $soapSql .= "     objective=?,";
        $soapSql .= "     assessment=?,";
        $soapSql .= "     plan=?";

        $soapResults = sqlInsert(
            $soapSql,
            array(
                $pid,
                $data["subjective"],
                $data["objective"],
                $data["assessment"],
                $data["plan"]
            )
        );

        if (!$soapResults) {
            return false;
        }

        $formSql = "INSERT INTO forms SET";
        $formSql .= "     date=NOW(),";
        $formSql .= "     encounter=?,";
        $formSql .= "     form_name='SOAP',";
        $formSql .= "     authorized='1',";
        $formSql .= "     form_id=?,";
        $formSql .= "     pid=?,";
        $formSql .= "     formdir='soap'";

        $formResults = sqlInsert(
            $formSql,
            array(
                $eid,
                $soapResults,
                $pid
            )
        );

        return array($soapResults, $formResults);
    }

    public function updateSoapNote($pid, $eid, $sid, $data)
    {
        $sql  = " UPDATE form_soap SET";
        $sql .= "     date=NOW(),";
        $sql .= "     activity=1,";
        $sql .= "     pid=?,";
        $sql .= "     subjective=?,";
        $sql .= "     objective=?,";
        $sql .= "     assessment=?,";
        $sql .= "     plan=?";
        $sql .= "     where id=?";

        return sqlStatement(
            $sql,
            array(
                $pid,
                $data["subjective"],
                $data["objective"],
                $data["assessment"],
                $data["plan"],
                $sid
            )
        );
    }

    public function updateVital($pid, $eid, $vid, $data)
    {
        $sql  = " UPDATE form_vitals SET";
        $sql .= "     date=NOW(),";
        $sql .= "     activity=1,";
        $sql .= "     pid=?,";
        $sql .= "     bps=?,";
        $sql .= "     bpd=?,";
        $sql .= "     weight=?,";
        $sql .= "     height=?,";
        $sql .= "     temperature=?,";
        $sql .= "     temp_method=?,";
        $sql .= "     pulse=?,";
        $sql .= "     respiration=?,";
        $sql .= "     note=?,";
        $sql .= "     waist_circ=?,";
        $sql .= "     head_circ=?,";
        $sql .= "     oxygen_saturation=?";
        $sql .= "     where id=?";

        return sqlStatement(
            $sql,
            array(
                $pid,
                $data["bps"],
                $data["bpd"],
                $data["weight"],
                $data["height"],
                $data["temperature"],
                $data["temp_method"],
                $data["pulse"],
                $data["respiration"],
                $data["note"],
                $data["waist_circ"],
                $data["head_circ"],
                $data["oxygen_saturation"],
                $vid
            )
        );
    }

    public function insertVital($pid, $eid, $data)
    {
        $vitalSql  = " INSERT INTO form_vitals SET";
        $vitalSql .= "     date=NOW(),";
        $vitalSql .= "     activity=1,";
        $vitalSql .= "     pid=?,";
        $vitalSql .= "     bps=?,";
        $vitalSql .= "     bpd=?,";
        $vitalSql .= "     weight=?,";
        $vitalSql .= "     height=?,";
        $vitalSql .= "     temperature=?,";
        $vitalSql .= "     temp_method=?,";
        $vitalSql .= "     pulse=?,";
        $vitalSql .= "     respiration=?,";
        $vitalSql .= "     note=?,";
        $vitalSql .= "     waist_circ=?,";
        $vitalSql .= "     head_circ=?,";
        $vitalSql .= "     oxygen_saturation=?";

        $vitalResults = sqlInsert(
            $vitalSql,
            array(
                $pid,
                $data["bps"],
                $data["bpd"],
                $data["weight"],
                $data["height"],
                $data["temperature"],
                $data["temp_method"],
                $data["pulse"],
                $data["respiration"],
                $data["note"],
                $data["waist_circ"],
                $data["head_circ"],
                $data["oxygen_saturation"]
            )
        );

        if (!$vitalResults) {
            return false;
        }

        $formSql = "INSERT INTO forms SET";
        $formSql .= "     date=NOW(),";
        $formSql .= "     encounter=?,";
        $formSql .= "     form_name='Vitals',";
        $formSql .= "     authorized='1',";
        $formSql .= "     form_id=?,";
        $formSql .= "     pid=?,";
        $formSql .= "     formdir='vitals'";

        $formResults = sqlInsert(
            $formSql,
            array(
                $eid,
                $vitalResults,
                $pid
            )
        );

        return array($vitalResults, $formResults);
    }

    public function getVitals($pid, $eid)
    {
        $sql  = "  SELECT fs.*";
        $sql .= "  FROM forms fo";
        $sql .= "  JOIN form_vitals fs on fs.id = fo.form_id";
        $sql .= "  WHERE fo.encounter = ?";
        $sql .= "    AND fs.pid = ?";

        $statementResults = sqlStatement($sql, array($eid, $pid));

        $results = array();
        while ($row = sqlFetchArray($statementResults)) {
            array_push($results, $row);
        }

        return $results;
    }

    public function getVital($pid, $eid, $vid)
    {
        $sql  = "  SELECT fs.*";
        $sql .= "  FROM forms fo";
        $sql .= "  JOIN form_vitals fs on fs.id = fo.form_id";
        $sql .= "  WHERE fo.encounter = ?";
        $sql .= "    AND fs.id = ?";
        $sql .= "    AND fs.pid = ?";

        return sqlQuery($sql, array($eid, $vid, $pid));
    }

    public function getSoapNotes($pid, $eid)
    {
        $sql  = "  SELECT fs.*";
        $sql .= "  FROM forms fo";
        $sql .= "  JOIN form_soap fs on fs.id = fo.form_id";
        $sql .= "  WHERE fo.encounter = ?";
        $sql .= "    AND fs.pid = ?";

        $statementResults = sqlStatement($sql, array($eid, $pid));

        $results = array();
        while ($row = sqlFetchArray($statementResults)) {
            array_push($results, $row);
        }

        return $results;
    }

    public function getSoapNote($pid, $eid, $sid)
    {
        $sql  = "  SELECT fs.*";
        $sql .= "  FROM forms fo";
        $sql .= "  JOIN form_soap fs on fs.id = fo.form_id";
        $sql .= "  WHERE fo.encounter = ?";
        $sql .= "    AND fs.id = ?";
        $sql .= "    AND fs.pid = ?";

        return sqlQuery($sql, array($eid, $sid, $pid));
    }
}
