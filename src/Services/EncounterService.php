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
     * Returns a single encounter record by encounter id and patient id.
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

    public function insertEncounter($pid, $data)
    {
        $encounter = generate_id();

        $sql .= " INSERT INTO form_encounter SET";
        $sql .= "     date = ?,";
        $sql .= "     onset_date = ?,";
        $sql .= "     reason = ?,";
        $sql .= "     facility = ?,";
        $sql .= "     pc_catid = ?,";
        $sql .= "     facility_id = ?,";
        $sql .= "     billing_facility = ?,";
        $sql .= "     sensitivity = ?,";
        $sql .= "     referral_source = ?,";
        $sql .= "     pid = ?,";
        $sql .= "     encounter = ?,";
        $sql .= "     pos_code = ?,";
        $sql .= "     external_id = ?,";
        $sql .= "     provider_id = ?,";
        $sql .= "     parent_encounter_id = ?";

        $results;
        addForm(
            $encounter,
            "New Patient Encounter",
            $results = sqlInsert(
                $sql,
                [
                $data["date"],
                $data["onset_date"],
                $data["reason"],
                $data["facility"],
                $data["pc_catid"],
                $data["facility_id"],
                $data["billing_facility"],
                $data["sensitivity"],
                $data["referral_source"],
                $pid,
                $encounter,
                $data["pos_code"],
                $data["external_id"],
                $data["provider_id"],
                $data["parent_enc_id"]
                ]
            ),
            "newpatient",
            $pid,
            $data["provider_id"],
            $data["date"]
        );

        if ($results) {
            return $encounter;
        }
    }

    public function updateEncounter($pid, $eid, $data)
    {
        $facilityService = new FacilityService();

        $facilityresult = $facilityService->getById($data["facility_id"]);
        $facility = $facilityresult['name'];

        $result = sqlQuery("SELECT sensitivity FROM form_encounter WHERE encounter = ?", array($eid));
        if ($result['sensitivity'] && !AclMain::aclCheckCore('sensitivities', $result['sensitivity'])) {
            return "You are not authorized to see this encounter.";
        }

        // See view.php to allow or disallow updates of the encounter date.
        $datepart = "";
        $sqlBindArray = array();
        if (AclMain::aclCheckCore('encounters', 'date_a')) {
            $datepart = "date = ?, ";
            $sqlBindArray[] = $data["date"];
        }

        array_push(
            $sqlBindArray,
            $data["onset_date"],
            $data["reason"],
            $facility,
            $data["pc_catid"],
            $data["facility_id"],
            $data["billing_facility"],
            $data["sensitivity"],
            $data["referral_source"],
            $data["pos_code"],
            $eid
        );

        $sql .= "  UPDATE form_encounter SET $datepart";
        $sql .= "     onset_date = ?, ";
        $sql .= "     reason = ?, ";
        $sql .= "     facility = ?, ";
        $sql .= "     pc_catid = ?, ";
        $sql .= "     facility_id = ?, ";
        $sql .= "     billing_facility = ?, ";
        $sql .= "     sensitivity = ?, ";
        $sql .= "     referral_source = ?, ";
        $sql .= "     pos_code = ? WHERE encounter = ?";

        return sqlStatement(
            $sql,
            $sqlBindArray
        );
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
