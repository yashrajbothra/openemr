<?php

/**
 * interface/modules/zend_modules/module/Carecoordination/src/Carecoordination/Model/EncounterccdadispatchTable.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Vinish K <vinish@zhservices.com>
 * @author    Riju K P <rijukp@zhservices.com>
 * @copyright Copyright (c) 2014 Z&H Consultancy Services Private Limited <sam@zhservices.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace Carecoordination\Model;

use Application\Model\ApplicationTable;
use Carecoordination\Model\CarecoordinationTable;
use CouchDB;
use Laminas\Db\Adapter\Driver\Pdo\Result;
use Laminas\Db\TableGateway\AbstractTableGateway;
use Matrix\Exception;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Uuid\UuidRegistry;

require_once(__DIR__ . "/../../../../../../../../custom/code_types.inc.php");
require_once(__DIR__ . "/../../../../../../../forms/vitals/report.php");

class EncounterccdadispatchTable extends AbstractTableGateway
{
    public function __construct()
    {
    }

    /*Fetch Patient data from EMR

    * @param    $pid
    * @param    $encounter
    * @return   $patient_data   Patient Data in XML format
    */
    public function getPatientdata($pid, $encounter)
    {
        $query = "select patient_data.*, l1.notes AS race_code, l1.title as race_title, l2.notes AS ethnicity_code, l2.title as ethnicity_title, l3.title as religion, l3.notes as religion_code, l4.notes as language_code, l4.title as language_title
                        from patient_data
                        left join list_options as l1 on l1.list_id=? AND l1.option_id=race
                        left join list_options as l2 on l2.list_id=? AND l2.option_id=ethnicity
			left join list_options AS l3 ON l3.list_id=? AND l3.option_id=religion
			left join list_options AS l4 ON l4.list_id=? AND l4.option_id=language
                        where pid=?";
        $appTable = new ApplicationTable();
        $row = $appTable->zQuery($query, array('race', 'ethnicity', 'religious_affiliation', 'language', $pid));

        foreach ($row as $result) {
            $patient_data = "<patient>
            <id>" . xmlEscape($result['pid']) . "</id>
            <encounter>" . xmlEscape($encounter) . "</encounter>
            <prefix>" . xmlEscape($result['title']) . "</prefix>
            <fname>" . xmlEscape($result['fname']) . "</fname>
            <mname>" . xmlEscape($result['mname']) . "</mname>
            <lname>" . xmlEscape($result['lname']) . "</lname>
            <birth_fname>" . xmlEscape($result['birth_fname']) . "</birth_fname>
            <birth_mname>" . xmlEscape($result['birth_mname']) . "</birth_mname>
            <birth_lname>" . xmlEscape($result['birth_lname']) . "</birth_lname>
            <street>" . xmlEscape($result['street']) . "</street>
            <city>" . xmlEscape($result['city']) . "</city>
            <state>" . xmlEscape($result['state']) . "</state>
            <postalCode>" . xmlEscape($result['postal_code']) . "</postalCode>
            <country>" . xmlEscape($result['country_code']) . "</country>
            <ssn>" . xmlEscape($result['ss'] ? $result['ss'] : 0) . "</ssn>
            <dob>" . xmlEscape(str_replace('-', '', $result['DOB'])) . "</dob>
            <gender>" . xmlEscape($result['sex']) . "</gender>
            <gender_code>" . xmlEscape(strtoupper(substr($result['sex'], 0, 1))) . "</gender_code>
            <status>" . xmlEscape($result['status'] ? $result['status'] : 'NULL') . "</status>
            <status_code>" . xmlEscape($result['status'] ? strtoupper(substr($result['status'], 0, 1)) : 0) . "</status_code>
            <phone_home>" . xmlEscape(($result['phone_home'] ? $result['phone_home'] : 0)) . "</phone_home>
            <phone_mobile>" . xmlEscape(($result['phone_home'] ? $result['phone_cell'] : 0)) . "</phone_mobile>
            <religion>" . xmlEscape(\Application\Listener\Listener::z_xlt($result['religion'] ? $result['religion'] : 'NULL')) . "</religion>
            <religion_code>" . xmlEscape($result['religion_code'] ? $result['religion_code'] : 0) . "</religion_code>
            <race>" . xmlEscape(\Application\Listener\Listener::z_xlt($result['race_title'])) . "</race>
            <race_code>" . xmlEscape($result['race_code']) . "</race_code>
            <ethnicity>" . xmlEscape(\Application\Listener\Listener::z_xlt($result['ethnicity_title'])) . "</ethnicity>
            <ethnicity_code>" . xmlEscape($result['ethnicity_code']) . "</ethnicity_code>
            <language>" . xmlEscape(\Application\Listener\Listener::z_xlt($result['language_title'])) . "</language>
            <language_code>" . xmlEscape($result['language_code']) . "</language_code>
            </patient>
		<guardian>
			<fname>" . xmlEscape($result['']) . "</fname>
			<lname>" . xmlEscape($result['']) . "</lname>
			<code>" . xmlEscape($result['']) . "</code>
			<relation>" . xmlEscape($result['guardianrelationship']) . "</relation>
			<display_name>" . xmlEscape($result['guardiansname']) . "</display_name>
			<street>" . xmlEscape($result['guardianaddress']) . "</street>
			<city>" . xmlEscape($result['guardiancity']) . "</city>
			<state>" . xmlEscape($result['guardianstate']) . "</state>
			<postalCode>" . xmlEscape($result['guardianpostalcode']) . "</postalCode>
			<country>" . xmlEscape($result['guardiancountry']) . "</country>
			<telecom>" . xmlEscape($result['guardianphone']) . "</telecom>
		</guardian>";
        }

        return $patient_data;
    }

    public function getProviderDetails($pid, $encounter)
    {
        $provider_details = '';
        if (!$encounter) {
            $query_enc = "SELECT encounter FROM form_encounter WHERE pid=? ORDER BY date DESC LIMIT 1";
            $appTable = new ApplicationTable();
            $res_enc = $appTable->zQuery($query_enc, array($pid));
            foreach ($res_enc as $row_enc) {
                $encounter = $row_enc['encounter'];
            }
        }

        $query = "SELECT * FROM form_encounter as fe
                        JOIN users AS u ON u.id =  fe.provider_id
                        JOIN facility AS f ON f.id = u.facility_id
                        WHERE fe.pid = ? AND fe.encounter = ?";
        $appTable = new ApplicationTable();
        $row = $appTable->zQuery($query, array($pid, $encounter));

        foreach ($row as $result) {
            $provider_details = "<encounter_provider>
                    <facility_id>" . xmlEscape($result['id']) . "</facility_id>
                    <facility_npi>" . xmlEscape($result['facility_npi']) . "</facility_npi>
                    <facility_oid>" . xmlEscape($result['oid']) . "</facility_oid>
                    <facility_name>" . xmlEscape($result['name']) . "</facility_name>
                    <facility_phone>" . xmlEscape(($result['phone'] ? $result['phone'] : 0)) . "</facility_phone>
                    <facility_fax>" . xmlEscape($result['fax']) . "</facility_fax>
                    <facility_street>" . xmlEscape($result['street']) . "</facility_street>
                    <facility_city>" . xmlEscape($result['city']) . "</facility_city>
                    <facility_state>" . xmlEscape($result['state']) . "</facility_state>
                    <facility_postal_code>" . xmlEscape($result['postal_code']) . "</facility_postal_code>
                    <facility_country_code>" . xmlEscape($result['country_code']) . "</facility_country_code>
                </encounter_provider>
            ";
        }

        return $provider_details;
    }

    public function getAuthor($pid, $encounter)
    {
        $author = '';
        $details = $this->getDetails('hie_author_id');

        $author = "
        <author>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
            <npi>" . xmlEscape($details['npi']) . "</npi>
        </author>";

        return $author;
    }

    public function getDataEnterer($pid, $encounter)
    {
        $data_enterer = '';
        $details = $this->getDetails('hie_data_enterer_id');

        $data_enterer = "
        <data_enterer>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
        </data_enterer>";

        return $data_enterer;
    }

    public function getInformant($pid, $encounter)
    {
        $informant = '';
        $details = $this->getDetails('hie_informant_id');
        $personal_informant = $this->getDetails('hie_personal_informant_id');

        $informant = "<informer>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
            <personal_informant>" . xmlEscape($this->getSettings('Carecoordination', 'hie_personal_informant_id')) . "</personal_informant>
        </informer>";

        return $informant;
    }

    public function getCustodian($pid, $encounter)
    {
        $custodian = '';
        $details = $this->getDetails('hie_custodian_id');

        $custodian = "<custodian>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <name>" . xmlEscape($details['organization']) . "</name>
            <organization>" . xmlEscape($details['organization']) . "</organization>
        </custodian>";

        return $custodian;
    }

    public function getInformationRecipient($pid, $encounter, $recipients, $params)
    {
        $information_recipient = '';
        $field_name = array();
        $details = $this->getDetails('hie_recipient_id');

        $appTable = new ApplicationTable();

        if ($recipients == 'hie') {
            $details['fname'] = 'MyHealth';
            $details['lname'] = '';
            $details['organization'] = '';
        } elseif ($recipients == 'emr_direct') {
            $query = "select fname, lname, organization, street, city, state, zip, phonew1 from users where email = ?";
            $field_name[] = $params;
        } elseif ($recipients == 'patient') {
            $query = "select fname, lname from patient_data WHERE pid = ?";
            $field_name[] = $params;
        } else {
            if (!$params) {
                $params = $_SESSION['authUserID'];
            }

            $query = "select fname, lname, organization, street, city, state, zip, phonew1 from users where id = ?";
            $field_name[] = $params;
        }

        if ($recipients != 'hie') {
            $res = $appTable->zQuery($query, $field_name);
            $result = $res->current();
            $details['fname'] = $result['fname'];
            $details['lname'] = $result['lname'];
            $details['organization'] = $result['organization'];
            $details['street'] = $result['street'];
            $details['city'] = $result['city'];
            $details['state'] = $result['state'];
            $details['zip'] = $result['zip'];
            $details['phonew1'] = $result['phonew1'];
        }

        $information_recipient = "<information_recipient>
        <fname>" . xmlEscape($details['fname']) . "</fname>
        <lname>" . xmlEscape($details['lname']) . "</lname>
        <organization>" . xmlEscape($details['organization']) . "</organization>
	    <street>" . xmlEscape($details['street']) . "</street>
	    <city>" . xmlEscape($details['city']) . "</city>
	    <state>" . xmlEscape($details['state']) . "</state>
	    <zip>" . xmlEscape($details['zip']) . "</zip>
	    <phonew1>" . xmlEscape($details['phonew1']) . "</phonew1>
        </information_recipient>";

        return $information_recipient;
    }

    public function getLegalAuthenticator($pid, $encounter)
    {
        $legal_authenticator = '';
        $details = $this->getDetails('hie_legal_authenticator_id');

        $legal_authenticator = "<legal_authenticator>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
        </legal_authenticator>";

        return $legal_authenticator;
    }

    public function getAuthenticator($pid, $encounter)
    {
        $authenticator = '';
        $details = $this->getDetails('hie_authenticator_id');

        $authenticator = "<authenticator>
            <streetAddressLine>" . xmlEscape($details['street']) . "</streetAddressLine>
            <city>" . xmlEscape($details['city']) . "</city>
            <state>" . xmlEscape($details['state']) . "</state>
            <postalCode>" . xmlEscape($details['zip']) . "</postalCode>
            <country>" . xmlEscape($details['']) . "</country>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
        </authenticator>";

        return $authenticator;
    }

    public function getPrimaryCareProvider($pid, $encounter)
    {
        // primary from demo
        $getprovider = $this->getProviderId($pid);
        if (!empty($getprovider)) { // from patient_data
            $details = $this->getUserDetails($getprovider);
        } else { // get from CCM setup
            $details = $this->getDetails('hie_primary_care_provider_id');
        }
        // Note for NPI: Many times a care team member may not have an NPI so instead of
        // an NPI OID use facility/document unique OID with user table reference for extension.
        $get_care_team_provider = explode("|", $this->getCareTeamProviderId($pid));
        $primary_care_provider = "
        <primary_care_provider>
          <provider>
            <prefix>" . xmlEscape($details['title']) . "</prefix>
            <fname>" . xmlEscape($details['fname']) . "</fname>
            <lname>" . xmlEscape($details['lname']) . "</lname>
            <speciality>" . xmlEscape($details['specialty']) . "</speciality>
            <organization>" . xmlEscape($details['organization']) . "</organization>
            <telecom>" . xmlEscape(($details['phonew1'] ? $details['phonew1'] : 0)) . "</telecom>
            <addr>" . xmlEscape($details['']) . "</addr>
            <table_id>" . xmlEscape("provider-" . $getprovider) . "</table_id>
            <npi>" . xmlEscape($details['npi'] ?: '') . "</npi>
            <physician_type>" . xmlEscape($details['physician_type']) . "</physician_type>
            <physician_type_code>" . xmlEscape($details['physician_type_code']) . "</physician_type_code>
            <taxonomy>" . xmlEscape($details['taxonomy']) . "</taxonomy>
            <taxonomy_description>" . xmlEscape($details['taxonomy_desc']) . "</taxonomy_description>
          </provider>
        </primary_care_provider>";
        $care_team_provider = "<care_team>";
        foreach ($get_care_team_provider as $team_member) {
            if ((int)$getprovider === (int)$team_member) {
                // primary should be a part of care team but just in case
                // I've kept primary separate. So either way, primary gets included.
                // in this case, we don't want to duplicate the provider.
                continue;
            }
            $details2 = $this->getUserDetails($team_member);
            if (empty($details2)) {
                continue;
            }
            $care_team_provider .= "<provider>
            <prefix>" . xmlEscape($details2['title']) . "</prefix>
            <fname>" . xmlEscape($details2['fname']) . "</fname>
            <lname>" . xmlEscape($details2['lname']) . "</lname>
            <speciality>" . xmlEscape($details2['specialty']) . "</speciality>
            <organization>" . xmlEscape($details2['organization']) . "</organization>
            <telecom>" . xmlEscape(($details2['phonew1'] ?: '')) . "</telecom>
            <addr>" . xmlEscape($details2['']) . "</addr>
            <table_id>" . xmlEscape("provider-" . $team_member) . "</table_id>
            <npi>" . xmlEscape($details2['npi']) . "</npi>
            <physician_type>" . xmlEscape($details2['physician_type']) . "</physician_type>
            <physician_type_code>" . xmlEscape($details2['physician_type_code']) . "</physician_type_code>
            <taxonomy>" . xmlEscape($details2['taxonomy']) . "</taxonomy>
            <taxonomy_description>" . xmlEscape($details2['taxonomy_desc']) . "</taxonomy_description>
          </provider>
          ";
        }
        $care_team_provider .= "</care_team>
        ";
        return $primary_care_provider . $care_team_provider;
    }

    /*
    #******************************************************#
    #                  CONTINUITY OF CARE                  #
    #******************************************************#
    */
    public function getAllergies($pid, $encounter)
    {
        $allergies = '';
        $query = "SELECT l.id, l.title, l.begdate, l.enddate, lo.title AS observation,
            SUBSTRING(lo.codes, LOCATE(':',lo.codes)+1, LENGTH(lo.codes)) AS observation_code,
						SUBSTRING(l.`diagnosis`,1,LOCATE(':',l.diagnosis)-1) AS code_type_real,
						l.reaction, l.diagnosis, l.diagnosis AS code
						FROM lists AS l
						LEFT JOIN list_options AS lo ON lo.list_id = ? AND lo.option_id = l.severity_al
						WHERE l.type = ? AND l.pid = ?";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array('severity_ccda', 'allergy', $pid));

        $allergies = "<allergies>";
        foreach ($res as $row) {
            $split_codes = explode(';', $row['code']);
            foreach ($split_codes as $key => $single_code) {
                $code = $code_text = $code_rx = $code_text_rx = $code_snomed = $code_text_snomed = $reaction_text = $reaction_code = '';
                $get_code_details = explode(':', $single_code);

                if ($get_code_details[0] == 'RXNORM' || $get_code_details[0] == 'RXCUI') {
                    $code_rx = $get_code_details[1];
                    $code_text_rx = lookup_code_descriptions($single_code);
                } elseif ($get_code_details[0] == 'SNOMED' || $get_code_details[0] == 'SNOMED-CT') {
                    $code_snomed = $get_code_details[1];
                    $code_text_snomed = lookup_code_descriptions($row['code']);
                } else {
                    $code = $get_code_details[1];
                    $code_text = lookup_code_descriptions($single_code);
                }

                $active = $status_table = '';

                if ($row['enddate']) {
                    $active = 'completed';
                    $allergy_status = 'completed';
                    $status_table = 'Resolved';
                    $status_code = '73425007';
                } else {
                    $active = 'completed';
                    $allergy_status = 'active';
                    $status_table = 'Active';
                    $status_code = '55561003';
                }

                if ($row['reaction']) {
                    $reaction_text = (new CarecoordinationTable())->getListTitle($row['reaction'], 'reaction', '');
                    $reaction_code = (new CarecoordinationTable())->getCodes($row['reaction'], 'reaction');
                    $reaction_code = explode(':', $reaction_code);
                }

                $allergies .= "<allergy>
                <id>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . $single_code)) . "</id>
                <sha_id>" . xmlEscape("36e3e930-7b14-11db-9fe1-0800200c9a66") . "</sha_id>
                <title>" . xmlEscape($row['title']) . ($single_code ? " [" . xmlEscape($single_code) . "]" : '') . "</title>
                <diagnosis_code>" . xmlEscape(($code ? $code : 0)) . "</diagnosis_code>
                <diagnosis>" . xmlEscape(($code_text ? \Application\Listener\Listener::z_xlt($code_text) : 'NULL')) . "</diagnosis>
                <rxnorm_code>" . xmlEscape(($code_rx ? $code_rx : 0)) . "</rxnorm_code>
                <rxnorm_code_text>" . xmlEscape(($code_text_rx ? \Application\Listener\Listener::z_xlt($code_text_rx) : 'NULL')) . "</rxnorm_code_text>
                <snomed_code>" . xmlEscape(($code_snomed ? $code_snomed : 0)) . "</snomed_code>
                <snomed_code_text>" . xmlEscape(($code_text_snomed ? \Application\Listener\Listener::z_xlt($code_text_snomed) : 'NULL')) . "</snomed_code_text>
                <status_table>" . ($status_table ? xmlEscape($status_table) : 'NULL') . "</status_table>
                <status>" . ($active ? xmlEscape($active) : 'NULL') . "</status>
                <allergy_status>" . ($allergy_status ? xmlEscape($allergy_status) : 'NULL') . "</allergy_status>
                <status_code>" . ($status_code ? xmlEscape($status_code) : 0) . "</status_code>
                <outcome>" . xmlEscape(($row['observation'] ? \Application\Listener\Listener::z_xlt($row['observation']) : 'NULL')) . "</outcome>
                <outcome_code>" . xmlEscape(($row['observation_code'] ? $row['observation_code'] : 0)) . "</outcome_code>
                <startdate>" . xmlEscape($row['begdate'] ? preg_replace('/-/', '', $row['begdate']) : "00000000") . "</startdate>
                <enddate>" . xmlEscape($row['enddate'] ? preg_replace('/-/', '', $row['enddate']) : "00000000") . "</enddate>
                <reaction_text>" . xmlEscape($reaction_text ? \Application\Listener\Listener::z_xlt($reaction_text) : 'NULL') . "</reaction_text>
                <reaction_code>" . xmlEscape($reaction_code[1] ?: '') . "</reaction_code>
                <reaction_code_type>" . xmlEscape(str_replace('-', ' ', $reaction_code[0]) ?: '') . "</reaction_code_type>
                <RxNormCode>" . xmlEscape($code_rx) . "</RxNormCode>
                <RxNormCode_text>" . xmlEscape(!empty($code_text_rx) ? $code_text_rx : $row['title']) . "</RxNormCode_text>
                </allergy>";
            }
        }

        $allergies .= "</allergies>";
        return $allergies;
    }

    public function getMedications($pid, $encounter)
    {
        $medications = '';
        $query = "select l.id, l.date_added, l.drug, l.dosage, l.quantity, l.size, l.substitute, l.drug_info_erx, l.active, SUBSTRING(l3.codes, LOCATE(':',l3.codes)+1, LENGTH(l3.codes)) AS route_code,
                       l.rxnorm_drugcode, l1.title as unit, l1.codes as unit_code,l2.title as form,SUBSTRING(l2.codes, LOCATE(':',l2.codes)+1, LENGTH(l2.codes)) AS form_code, l3.title as route, l4.title as `interval`,
                       u.title, u.fname, u.lname, u.mname, u.npi, u.street, u.streetb, u.city, u.state, u.zip, u.phonew1, l.note
                       from prescriptions as l
                       left join list_options as l1 on l1.option_id=unit AND l1.list_id = ?
                       left join list_options as l2 on l2.option_id=form AND l2.list_id = ?
                       left join list_options as l3 on l3.option_id=route AND l3.list_id = ?
                       left join list_options as l4 on l4.option_id=`interval` AND l4.list_id = ?
                       left join users as u on u.id = l.provider_id
                       where l.patient_id = ? and l.active = 1";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array('drug_units', 'drug_form', 'drug_route', 'drug_interval', $pid));

        $medications = "<medications>";
        foreach ($res as $row) {
            if (!$row['rxnorm_drugcode']) {
                $row['rxnorm_drugcode'] = $this->generate_code($row['drug']);
            }

            $unit = $str = $active = '';

            if ($row['size'] > 0) {
                $unit = $row['size'] . " " . \Application\Listener\Listener::z_xlt($row['unit']) . " ";
            }

            $str = $unit . " " . \Application\Listener\Listener::z_xlt($row['route']) . " " . $row['dosage'] . " " . \Application\Listener\Listener::z_xlt($row['form'] . " " . $row['interval']);

            if ($row['active'] > 0) {
                $active = 'active';
            } else {
                $active = 'completed';
            }

            if ($row['date_added']) {
                $start_date = str_replace('-', '', $row['date_added']);
                $start_date_formatted = \Application\Model\ApplicationTable::fixDate($row['date_added'], $GLOBALS['date_display_format'], 'yyyy-mm-dd');
                ;
            }

            $medications .= "<medication>
    <id>" . xmlEscape($row['id']) . "</id>
    <extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
    <sha_extension>" . xmlEscape("cdbd33f0-6cde-11db-9fe1-0800200c9a66") . "</sha_extension>
    <performer_name>" . xmlEscape($row['fname'] . " " . $row['mname'] . " " . $row['lname']) . "</performer_name>
    <fname>" . xmlEscape($row['fname']) . "</fname>
    <mname>" . xmlEscape($row['mname']) . "</mname>
    <lname>" . xmlEscape($row['lname']) . "</lname>
    <title>" . xmlEscape($row['title']) . "</title>
    <npi>" . xmlEscape($row['npi']) . "</npi>
    <address>" . xmlEscape($row['street']) . "</address>
    <city>" . xmlEscape($row['city']) . "</city>
    <state>" . xmlEscape($row['state']) . "</state>
    <zip>" . xmlEscape($row['zip']) . "</zip>
    <work_phone>" . xmlEscape($row['phonew1']) . "</work_phone>
    <drug>" . xmlEscape($row['drug']) . "</drug>
    <direction>" . xmlEscape($str) . "</direction>
    <dosage>" . xmlEscape($row['dosage']) . "</dosage>
    <size>" . xmlEscape(($row['size'] ? $row['size'] : 0)) . "</size>
    <unit>" . xmlEscape(($row['unit'] ? preg_replace('/\s*/', '', \Application\Listener\Listener::z_xlt($row['unit'])) : '')) . "</unit>
    <unit_code>" . xmlEscape(($row['unit_code'] ? $row['unit_code'] : 0)) . "</unit_code>
    <form>" . xmlEscape(\Application\Listener\Listener::z_xlt($row['form'])) . "</form>
    <form_code>" . xmlEscape(\Application\Listener\Listener::z_xlt($row['form_code'])) . "</form_code>
    <route_code>" . xmlEscape($row['route_code']) . "</route_code>
    <route>" . xmlEscape($row['route']) . "</route>
    <interval>" . xmlEscape(\Application\Listener\Listener::z_xlt($row['interval'])) . "</interval>
    <start_date>" . xmlEscape($start_date) . "</start_date>
    <start_date_formatted>" . xmlEscape($row['date_added']) . "</start_date_formatted>
    <end_date>" . xmlEscape('00000000') . "</end_date>
    <status>" . xmlEscape($active) . "</status>
    <indications>" . xmlEscape(($row['pres_erx_diagnosis_name'] ? $row['pres_erx_diagnosis_name'] : 'NULL')) . "</indications>
    <indications_code>" . xmlEscape(($row['pres_erx_diagnosis'] ? $row['pres_erx_diagnosis'] : 0)) . "</indications_code>
    <instructions>" . xmlEscape($row['note']) . "</instructions>
    <rxnorm>" . xmlEscape($row['rxnorm_drugcode']) . "</rxnorm>
    <provider_id></provider_id>
    <provider_name></provider_name>
    </medication>";
        }

        $medications .= "</medications>";
        return $medications;
    }

    public function getProblemList($pid, $encounter)
    {
        UuidRegistry::createMissingUuidsForTables(['lists']);
        $problem_lists = '';
        $query = "select l.*, lo.title as observation, lo.codes as observation_code, l.diagnosis AS code
    from lists AS l
    left join list_options as lo on lo.option_id = l.outcome AND lo.list_id = ?
    where l.type = ? and l.pid = ? AND l.outcome != ?"; // patched out /* AND l.id NOT IN(SELECT list_id FROM issue_encounter WHERE pid = ?)*/
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array('outcome', 'medical_problem', $pid, 1));

        $problem_lists .= '<problem_lists>';
        foreach ($res as $row) {
            $row['uuid'] = UuidRegistry::uuidToString($row['uuid']);
            $split_codes = explode(';', $row['code']);
            foreach ($split_codes as $key => $single_code) {
                $get_code_details = explode(':', $single_code);
                $code_type = $get_code_details[0];
                $code_type = ($code_type == 'SNOMED' || $code_type == 'SNOMED-CT') ? "SNOMED CT" : "ICD-10-CM";
                $code = $get_code_details[1];
                $code_text = lookup_code_descriptions($single_code);

                $age = $this->getAge($pid, $row['begdate']);
                $start_date = str_replace('-', '', $row['begdate']);
                $end_date = str_replace('-', '', $row['enddate']);

                $status = $status_table = '';
                $start_date = $start_date ?: '0';
                $end_date = $end_date ?: '0';

                //Active - 55561003     Completed - 73425007
                if ($end_date) {
                    $status = 'completed';
                    $status_table = 'Resolved';
                    $status_code = '73425007';
                } else {
                    $status = 'active';
                    $status_table = 'Active';
                    $status_code = '55561003';
                }

                $observation = $row['observation'];
                $observation_code = explode(':', $row['observation_code']);
                $observation_code = $observation_code[1];
                $problem_lists .= "<problem>
                <problem_id>" . ($code ? xmlEscape($row['$id']) : '') . "</problem_id>
                <extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
                <sha_extension>" . xmlEscape($row['uuid']) . "</sha_extension>
                <title>" . xmlEscape($row['title']) . ($single_code ? " [" . xmlEscape($single_code) . "]" : '') . "</title>
                <code>" . ($code ? xmlEscape($code) : '') . "</code>
                <code_type>" . ($code ? xmlEscape($code_type) : '') . "</code_type>
                <code_text>" . xmlEscape(($code_text ?: '')) . "</code_text>
                <age>" . xmlEscape($age) . "</age>
                <start_date_table>" . xmlEscape($row['begdate']) . "</start_date_table>
                <start_date>" . xmlEscape($start_date) . "</start_date>
                <end_date>" . xmlEscape($end_date) . "</end_date>
                <status>" . xmlEscape($status) . "</status>
                <status_table>" . xmlEscape($status_table) . "</status_table>
                <status_code>" . xmlEscape($status_code) . "</status_code>
                <observation>" . xmlEscape(($observation ? \Application\Listener\Listener::z_xlt($observation) : 'NULL')) . "</observation>
                <observation_code>" . xmlEscape(($observation_code ?: '')) . "</observation_code>
                <diagnosis>" . xmlEscape($code ?: '') . "</diagnosis>
					</problem>";
            }
        }

        $problem_lists .= '</problem_lists>';
        return $problem_lists;
    }

    public function getMedicalDeviceList($pid, $encounter)
    {
        $medical_devices = '';
        $query = "select l.*, lo.title as observation, lo.codes as observation_code, l.diagnosis AS code
    from lists AS l
    left join list_options as lo on lo.option_id = l.outcome AND lo.list_id = ?
    where l.type = ? and l.pid = ? AND l.outcome != ? AND l.id NOT IN(SELECT list_id FROM issue_encounter WHERE pid = ?)";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array('outcome', 'medical_device', $pid, 1, $pid));

        $medical_devices .= '<medical_devices>';
        foreach ($res as $row) {
            $split_codes = explode(';', $row['code']);
            foreach ($split_codes as $key => $single_code) {
                $get_code_details = explode(':', $single_code);
                $code_type = $get_code_details[0];
                $code_type = ($code_type == 'SNOMED' || $code_type == 'SNOMED-CT') ? "SNOMED CT" : "ICD-10-CM";
                $code = $get_code_details[1];
                $code_text = lookup_code_descriptions($single_code);

                $start_date = str_replace('-', '', $row['begdate']);
                $end_date = str_replace('-', '', $row['enddate']);

                $status = $status_table = '';
                $start_date = $start_date ?: '';
                $end_date = $end_date ?: '';

                //Active - 55561003     Completed - 73425007
                if ($end_date) {
                    $status = 'completed';
                    $status_table = 'Resolved';
                    $status_code = '73425007';
                } else {
                    $status = 'active';
                    $status_table = 'Active';
                    $status_code = '55561003';
                }

                $observation = $row['observation'];
                $observation_code = explode(':', $row['observation_code']);
                $observation_code = $observation_code[1];

                $medical_devices .= "<device>
                <extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
                <sha_extension>" . xmlEscape($this->formatUid($_SESSION['site_id'] . $row['udi'])) . "</sha_extension>
                <title>" . xmlEscape($row['title']) . ($single_code ? " [" . xmlEscape($single_code) . "]" : '') . "</title>
                <code>" . ($code ? xmlEscape($code) : '') . "</code>
                <code_type>" . ($code ? xmlEscape($code_type) : '') . "</code_type>
                <code_text>" . xmlEscape(($code_text ?: '')) . "</code_text>
                <udi>" . xmlEscape($row['udi']) . "</udi>
                <start_date_table>" . xmlEscape($row['begdate']) . "</start_date_table>
                <start_date>" . xmlEscape($start_date) . "</start_date>
                <end_date>" . xmlEscape($end_date) . "</end_date>
                <status>" . xmlEscape($status) . "</status>
                <status_table>" . xmlEscape($status_table) . "</status_table>
                <status_code>" . xmlEscape($status_code) . "</status_code>
                <observation>" . xmlEscape(($observation ? \Application\Listener\Listener::z_xlt($observation) : 'NULL')) . "</observation>
                <observation_code>" . xmlEscape(($observation_code ?: '')) . "</observation_code>
                <diagnosis>" . xmlEscape($code ?: '') . "</diagnosis>
                </device>";
            }
        }

        $medical_devices .= '</medical_devices>';
        return $medical_devices;
    }

    public function getImmunization($pid, $encounter)
    {
        $immunizations = '';
        $query = "SELECT im.*, cd.code_text, DATE(administered_date) AS administered_date,
		    DATE_FORMAT(administered_date,'%Y%m%d') AS administered_formatted, lo.title as route_of_administration,
		    u.title, u.fname, u.mname, u.lname, u.npi, u.street, u.streetb, u.city, u.state, u.zip, u.phonew1,
		    f.name, f.phone, SUBSTRING(lo.codes, LOCATE(':',lo.codes)+1, LENGTH(lo.codes)) AS route_code
		    FROM immunizations AS im
		    LEFT JOIN codes AS cd ON cd.code = im.cvx_code
		    JOIN code_types AS ctype ON ctype.ct_key = 'CVX' AND ctype.ct_id=cd.code_type
		    LEFT JOIN list_options AS lo ON lo.list_id = 'drug_route' AND lo.option_id = im.route
		    LEFT JOIN users AS u ON u.id = im.administered_by_id
		    LEFT JOIN facility AS f ON f.id = u.facility_id
		    WHERE im.patient_id=?";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($pid));

        $immunizations .= '<immunizations>';
        foreach ($res as $row) {
            $immunizations .= "
	    <immunization>
		<extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
		<sha_extension>" . xmlEscape("e6f1ba43-c0ed-4b9b-9f12-f435d8ad8f92") . "</sha_extension>
		<id>" . xmlEscape($row['id']) . "</id>
		<cvx_code>" . xmlEscape($row['cvx_code']) . "</cvx_code>
		<code_text>" . xmlEscape($row['code_text']) . "</code_text>
		<reaction>" . xmlEscape($row['reaction']) . "</reaction>
		<npi>" . xmlEscape($row['npi']) . "</npi>
		<administered_by>" . xmlEscape($row['administered_by']) . "</administered_by>
		<fname>" . xmlEscape($row['fname']) . "</fname>
		<mname>" . xmlEscape($row['mname']) . "</mname>
		<lname>" . xmlEscape($row['lname']) . "</lname>
		<title>" . xmlEscape($row['title']) . "</title>
		<address>" . xmlEscape($row['street']) . "</address>
		<city>" . xmlEscape($row['city']) . "</city>
		<state>" . xmlEscape($row['state']) . "</state>
		<zip>" . xmlEscape($row['zip']) . "</zip>
		<work_phone>" . xmlEscape($row['phonew1']) . "</work_phone>
		<administered_on>" . xmlEscape($row['administered_date']) . "</administered_on>
		<administered_formatted>" . xmlEscape($row['administered_formatted']) . "</administered_formatted>
		<note>" . xmlEscape($row['note']) . "</note>
		<route_of_administration>" . xmlEscape(\Application\Listener\Listener::z_xlt($row['route_of_administration'])) . "</route_of_administration>
		<route_code>" . xmlEscape($row['route_code']) . "</route_code>
		<status>completed</status>
		<facility_name>" . xmlEscape($row['name']) . "</facility_name>
		<facility_phone>" . xmlEscape($row['phone']) . "</facility_phone>
	    </immunization>";
        }

        $immunizations .= '</immunizations>';

        return $immunizations;
    }

    public function getProcedures($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " b.encounter = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $procedure = '';
        $query = "select b.id, b.date as proc_date, b.code_text, b.code, fe.date,
	u.fname, u.lname, u.mname, u.npi, u.street, u.city, u.state, u.zip,
	f.id as fid, f.name, f.phone, f.street as fstreet, f.city as fcity, f.state as fstate, f.postal_code as fzip, f.country_code, f.phone as fphone
	from billing as b
        LEFT join code_types as ct on ct.ct_key
        LEFT join codes as c on c.code = b.code AND c.code_type = ct.ct_id
        LEFT join form_encounter as fe on fe.pid = b.pid AND fe.encounter = b.encounter
	LEFT JOIN users AS u ON u.id = b.provider_id
	LEFT JOIN facility AS f ON f.id = fe.facility_id
        where $wherCon b.pid = ? and b.activity = ?";
        array_push($sqlBindArray, $pid, 1);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);

        $procedure = '<procedures>';
        foreach ($res as $row) {
            $procedure .= "<procedure>
		    <extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
		    <sha_extension>" . xmlEscape("d68b7e32-7810-4f5b-9cc2-acd54b0fd85d") . "</sha_extension>
                    <description>" . xmlEscape($row['code_text']) . "</description>
		    <code>" . xmlEscape($row['code']) . "</code>
                    <date>" . xmlEscape(substr($row['date'], 0, 10)) . "</date>
		    <npi>" . xmlEscape($row['npi']) . "</npi>
		    <fname>" . xmlEscape($row['fname']) . "</fname>
		    <mname>" . xmlEscape($row['mname']) . "</mname>
		    <lname>" . xmlEscape($row['lname']) . "</lname>
		    <address>" . xmlEscape($row['street']) . "</address>
		    <city>" . xmlEscape($row['city']) . "</city>
		    <state>" . xmlEscape($row['state']) . "</state>
		    <zip>" . xmlEscape($row['zip']) . "</zip>
		    <work_phone>" . xmlEscape($row['phonew1']) . "</work_phone>
		    <facility_extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['fid'])) . "</facility_extension>
		    <facility_sha_extension>" . xmlEscape("c2ee9ee9-ae31-4628-a919-fec1cbb58686") . "</facility_sha_extension>
		    <facility_name>" . xmlEscape($row['name']) . "</facility_name>
		    <facility_address>" . xmlEscape($row['fstreet']) . "</facility_address>
		    <facility_city>" . xmlEscape($row['fcity']) . "</facility_city>
		    <facility_state>" . xmlEscape($row['fstate']) . "</facility_state>
		    <facility_country>" . xmlEscape($row['country_code']) . "</facility_country>
		    <facility_zip>" . xmlEscape($row['fzip']) . "</facility_zip>
		    <facility_phone>" . xmlEscape($row['fphone']) . "</facility_phone>
		    <procedure_date>" . xmlEscape(preg_replace('/-/', '', substr($row['proc_date'], 0, 10))) . "</procedure_date>
                </procedure>";
        }

        $procedure .= '</procedures>';
        return $procedure;
    }

    public function getResults($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " po.encounter_id = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $results = '';
        $query = "SELECT prs.result AS result_value, prs.units, prs.range, prs.result_text as order_title, prs.result_code, prs.procedure_result_id,
	    prs.result_text as result_desc, prs.procedure_result_id AS test_code, poc.procedure_code, poc.procedure_name, poc.diagnoses, po.date_ordered, prs.date AS result_time, prs.abnormal AS abnormal_flag,po.order_status AS order_status
	    FROM procedure_order AS po
	    JOIN procedure_order_code as poc on poc.procedure_order_id = po.procedure_order_id
	    JOIN procedure_report AS pr ON pr.procedure_order_id = po.procedure_order_id
	    JOIN procedure_result AS prs ON prs.procedure_report_id = pr.procedure_report_id
        WHERE $wherCon po.patient_id = ? AND prs.result NOT IN ('DNR','TNP')";
        array_push($sqlBindArray, $pid);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);

        $results_list = array();
        foreach ($res as $row) {
            if (empty($row['result_code']) && empty($row['abnormal_flag'])) {
                continue;
            }
            $results_list[$row['test_code']]['test_code'] = $row['test_code'];
            $results_list[$row['test_code']]['order_title'] = $row['order_title'];
            $results_list[$row['test_code']]['order_status'] = $row['order_status'];
            $results_list[$row['test_code']]['date_ordered'] = substr(str_replace("-", '', $row['date_ordered']), 0, 8);
            $results_list[$row['test_code']]['date_ordered_table'] = $row['date_ordered'];
            $results_list[$row['test_code']]['procedure_code'] = $row['procedure_code'];
            $results_list[$row['test_code']]['procedure_name'] = $row['procedure_name'];
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['result_code'] = ($row['result_code'] ? $row['result_code'] : 0);
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['result_desc'] = $row['result_desc'];
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['units'] = $row['units'];
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['range'] = $row['range'];
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['result_value'] = $row['result_value'];
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['result_time'] = substr(preg_replace('/-/', '', $row['result_time']), 0, 8);
            $results_list[$row['test_code']]['subtest'][$row['procedure_result_id']]['abnormal_flag'] = $row['abnormal_flag'];
        }

        $results = '<results>';
        foreach ($results_list as $row) {
            $order_status = $order_status_table = '';
            if ($row['order_status'] == 'complete') {
                $order_status = 'completed';
                $order_status_table = 'completed';
            } elseif ($row['order_status'] == 'pending') {
                $order_status = 'active';
                $order_status_table = 'pending';
            } else {
                $order_status = 'completed';
                $order_status_table = '';
            }

            $results .= '<result>
		<extension>' . xmlEscape(base64_encode($_SESSION['site_id'] . $row['test_code'])) . '</extension>
		<root>' . xmlEscape("7d5a02b0-67a4-11db-bd13-0800200c9a66") . '</root>
		<date_ordered>' . xmlEscape($row['date_ordered']) . '</date_ordered>
		<date_ordered_table>' . xmlEscape($row['date_ordered_table']) . '</date_ordered_table>
        <title>' . xmlEscape($row['order_title']) . '</title>
		<test_code>' . xmlEscape($row['procedure_code']) . '</test_code>
		<test_name>' . xmlEscape($row['procedure_name']) . '</test_name>
        <order_status_table>' . xmlEscape($order_status_table) . '</order_status_table>
        <order_status>' . xmlEscape($order_status) . '</order_status>';
            foreach ($row['subtest'] as $row_1) {
                $units = $row_1['units'] ?: '';
                $highlow = preg_split("/[\s,-\--]+/", $row_1['range']);
                $results .= '
		    <subtest>
			<extension>' . xmlEscape(base64_encode($_SESSION['site_id'] . $row['result_code'])) . '</extension>
			<root>' . xmlEscape("7d5a02b0-67a4-11db-bd13-0800200c9a66") . '</root>
			<range>' . xmlEscape($row_1['range']) . '</range>
			<low>' . xmlEscape(trim($highlow[0])) . '</low>
			<high>' . xmlEscape(trim($highlow[1])) . '</high>
			<unit>' . xmlEscape($units) . '</unit>
			<result_code>' . xmlEscape($row_1['result_code']) . '</result_code>
			<result_desc>' . xmlEscape($row_1['result_desc']) . '</result_desc>
			<result_value>' . xmlEscape(($row_1['result_value'] ? $row_1['result_value'] : 0)) . '</result_value>
			<result_time>' . xmlEscape($row_1['result_time']) . '</result_time>
			<abnormal_flag>' . xmlEscape($row_1['abnormal_flag']) . '</abnormal_flag>
		    </subtest>';
            }

            $results .= '
	    </result>';
        }

        $results .= '</results>';
        return $results;
    }

    /*
    #**************************************************#
    #                ENCOUNTER HISTORY                 #
    #**************************************************#
    */
    public function getEncounterHistory($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " fe.encounter = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $results = "";
        $query = "SELECT fe.date, fe.encounter,fe.reason,
	    f.id as fid, f.name, f.phone, f.street as fstreet, f.city as fcity, f.state as fstate, f.postal_code as fzip, f.country_code, f.phone as fphone, f.facility_npi as fnpi,
	    f.facility_code as foid, u.fname, u.mname, u.lname, u.npi, u.street, u.city, u.state, u.zip, u.phonew1, cat.pc_catname, lo.title AS physician_type, lo.codes AS physician_type_code,
	    SUBSTRING(ll.diagnosis, LENGTH('SNOMED-CT:')+1, LENGTH(ll.diagnosis)) AS encounter_diagnosis, ll.diagnosis as raw_diagnosis,  ll.title, ll.begdate, ll.enddate
	    FROM form_encounter AS fe
	    LEFT JOIN facility AS f ON f.id=fe.facility_id
	    LEFT JOIN users AS u ON u.id=fe.provider_id
	    LEFT JOIN openemr_postcalendar_categories AS cat ON cat.pc_catid=fe.pc_catid
	    LEFT JOIN list_options AS lo ON lo.list_id = 'physician_type' AND lo.option_id = u.physician_type
	    LEFT JOIN issue_encounter AS ie ON ie.encounter=fe.encounter AND ie.pid=fe.pid
	    LEFT JOIN lists AS ll ON ll.id=ie.list_id AND ll.pid=fe.pid
	    WHERE $wherCon fe.pid = ? ORDER BY fe.date";
        array_push($sqlBindArray, $pid);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);

        $results = "<encounter_list>";
        foreach ($res as $row) {
            $tmp = explode(":", $row['physician_type_code']);
            $physician_code_type = str_replace('-', ' ', $tmp[0]);
            $row['physician_type_code'] = $tmp[1];
            $encounter_reason = '';
            if ($row['reason'] !== '') {
                $encounter_reason = "<encounter_reason>" . xmlEscape($this->date_format(substr($row['date'], 0, 10)) . " - " . $row['reason']) . "</encounter_reason>";
            }

            $codes = "";
            $query_procedures = "SELECT c.code, c.code_text FROM billing AS b
			    JOIN code_types AS ct ON ct.ct_key = ?
			    JOIN codes AS c ON c.code = b.code AND c.code_type = ct.ct_id
			    WHERE b.pid = ? AND b.code_type = ? AND activity = 1 AND b.encounter = ?";
            $appTable_procedures = new ApplicationTable();
            $res_procedures = $appTable_procedures->zQuery($query_procedures, array('CPT4', $pid, 'CPT4', $row['encounter']));
            foreach ($res_procedures as $row_procedures) {
                $codes .= "
                <procedures>
                <code>" . xmlEscape($row_procedures['code']) . "</code>
                <code_type>" . xmlEscape("CPT4") . "</code_type>
                <text>" . xmlEscape($row_procedures['code_text']) . "</text>
                </procedures>";
            }
            $encounter_diagnosis = "";
            if ($row['encounter_diagnosis']) {
                $tmp = explode(":", $row['raw_diagnosis']);
                $code_type = str_replace('-', ' ', $tmp[0]);
                $encounter_activity = '';
                if ($row['enddate'] !== '') {
                    $encounter_activity = 'Completed';
                } else {
                    $encounter_activity = 'Active';
                }
                // this just duplicates in all procedures.
                // from problem attached to encounter
                $encounter_diagnosis = "
                <encounter_diagnosis>
                <code>" . xmlEscape($tmp[1]) . "</code>
                <code_type>" . xmlEscape($code_type) . "</code_type>
                <text>" . xmlEscape(\Application\Listener\Listener::z_xlt($row['title'])) . "</text>
                <status>" . xmlEscape($encounter_activity) . "</status>
                </encounter_diagnosis>";
                $codes .= $encounter_diagnosis;
            }
            $location_details = ($row['name'] !== '') ? (',' . $row['fstreet'] . ',' . $row['fcity'] . ',' . $row['fstate'] . ' ' . $row['fzip']) : '';
            $results .= "
	    <encounter>
		<extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['encounter'])) . "</extension>
		<sha_extension>" . xmlEscape($this->formatUid($_SESSION['site_id'] . $row['encounter'])) . "</sha_extension>
		<encounter_id>" . xmlEscape($row['encounter']) . "</encounter_id>
		<visit_category>" . xmlEscape($row['pc_catname']) . "</visit_category>
		<performer>" . xmlEscape($row['fname'] . " " . $row['mname'] . " " . $row['lname']) . "</performer>
		<physician_type_code>" . xmlEscape($row['physician_type_code']) . "</physician_type_code>
		<physician_type>" . xmlEscape($row['physician_type']) . "</physician_type>
        <physician_code_type>" . xmlEscape($physician_code_type) . "</physician_code_type>
		<npi>" . xmlEscape($row['npi']) . "</npi>
		<fname>" . xmlEscape($row['fname']) . "</fname>
		<mname>" . xmlEscape($row['mname']) . "</mname>
		<lname>" . xmlEscape($row['lname']) . "</lname>
		<street>" . xmlEscape($row['street']) . "</street>
		<city>" . xmlEscape($row['city']) . "</city>
		<state>" . xmlEscape($row['state']) . "</state>
		<zip>" . xmlEscape($row['zip']) . "</zip>
		<work_phone>" . xmlEscape($row['phonew1']) . "</work_phone>
		<location>" . xmlEscape($row['name']) . "</location>
        <location_details>" . xmlEscape($location_details) . "</location_details>
		<date>" . xmlEscape($this->date_format(substr($row['date'], 0, 10))) . "</date>
		<date_formatted>" . xmlEscape(str_replace("-", '', substr($row['date'], 0, 10))) . "</date_formatted>
		<facility_extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['fid'])) . "</facility_extension>
		<facility_sha_extension>" . xmlEscape($this->formatUid($_SESSION['site_id'] . $row['fid'])) . "</facility_sha_extension>
		<facility_npi>" . xmlEscape($row['fnpi']) . "</facility_npi>
		<facility_oid>" . xmlEscape($row['foid']) . "</facility_oid>
		<facility_name>" . xmlEscape($row['name']) . "</facility_name>
		<facility_address>" . xmlEscape($row['fstreet']) . "</facility_address>
		<facility_city>" . xmlEscape($row['fcity']) . "</facility_city>
		<facility_state>" . xmlEscape($row['fstate']) . "</facility_state>
		<facility_country>" . xmlEscape($row['country_code']) . "</facility_country>
		<facility_zip>" . xmlEscape($row['fzip']) . "</facility_zip>
		<facility_phone>" . xmlEscape($row['fphone']) . "</facility_phone>
		<encounter_procedures>$codes</encounter_procedures>
		$encounter_diagnosis
        $encounter_reason
	    </encounter>";
        }

        $results .= "</encounter_list>";
        return $results;
    }

    /*
    #**************************************************#
    #                  PROGRESS NOTES                  #
    #**************************************************#
    */
    public function getProgressNotes($pid, $encounter)
    {
        $progress_notes = '';
        $formTables_details = $this->fetchFields('progress_note', 'assessment_plan', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $progress_notes .= "<progressNotes>";
        foreach ($result as $row) {
            foreach ($row as $key => $value) {
                $progress_notes .= "<item>" . xmlEscape($value) . "</item>";
            }
        }

        $progress_notes .= "</progressNotes>";

        return $progress_notes;
    }

    /*
    #**************************************************#
    #                DISCHARGE SUMMARY                 #
    #**************************************************#
    */
    public function getHospitalCourse($pid, $encounter)
    {
        $hospital_course = '';
        $formTables_details = $this->fetchFields('discharge_summary', 'hospital_course', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $hospital_course .= "<hospitalCourse><item>";
        foreach ($result as $row) {
            $hospital_course .= xmlEscape(implode(' ', $row));
        }

        $hospital_course .= "</item></hospitalCourse>";

        return $hospital_course;
    }

    public function getDischargeDiagnosis($pid, $encounter)
    {
        $discharge_diagnosis = '';
        $formTables_details = $this->fetchFields('discharge_summary', 'hospital_discharge_diagnosis', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $discharge_diagnosis .= "<dischargediagnosis><item>";
        foreach ($result as $row) {
            $discharge_diagnosis .= xmlEscape(implode(' ', $row));
        }

        $discharge_diagnosis .= "</item></dischargediagnosis>";

        return $discharge_diagnosis;
    }

    public function getDischargeMedications($pid, $encounter)
    {
        $discharge_medications = '';
        $formTables_details = $this->fetchFields('discharge_summary', 'hospital_discharge_medications', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $discharge_medications .= "<dischargemedication><item>";
        foreach ($result as $row) {
            $discharge_medications .= xmlEscape(implode(' ', $row));
        }

        $discharge_medications .= "</item></dischargemedication>";

        return $discharge_medications;
    }

    /*
    #***********************************************#
    #               PROCEDURE NOTES                 #
    #***********************************************#
    Sub section of PROCEDURE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $complications  XML which contains the details collected from the patient.
    */
    public function getComplications($pid, $encounter)
    {
        $complications = '';
        $formTables_details = $this->fetchFields('procedure_note', 'complications', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $complications .= "<complications>";
        $complications .= "<age>" . xmlEscape($this->getAge($pid)) . "</age><item>";
        foreach ($result as $row) {
            $complications .= xmlEscape(implode(' ', $row));
        }

        $complications .= "</item></complications>";

        return $complications;
    }

    /*
    Sub section of PROCEDURE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $procedure_diag  XML which contains the details collected from the patient.
    */
    public function getPostProcedureDiag($pid, $encounter)
    {
        $procedure_diag = '';
        $formTables_details = $this->fetchFields('procedure_note', 'postprocedure_diagnosis', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $procedure_diag .= '<procedure_diagnosis>';
        $procedure_diag .= "<age>" . xmlEscape($this->getAge($pid)) . "</age><item>";
        foreach ($result as $row) {
            $procedure_diag .= xmlEscape(implode(' ', $row));
        }

        $procedure_diag .= '</item></procedure_diagnosis>';

        return $procedure_diag;
    }

    /*
    Sub section of PROCEDURE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $procedure_description  XML which contains the details collected from the patient.
    */
    public function getProcedureDescription($pid, $encounter)
    {
        $procedure_description = '';
        $formTables_details = $this->fetchFields('procedure_note', 'procedure_description', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $procedure_description .= "<procedure_description><item>";
        foreach ($result as $row) {
            $procedure_description .= xmlEscape(implode(' ', $row));
        }

        $procedure_description .= "</item></procedure_description>";

        return $procedure_description;
    }

    /*
    Sub section of PROCEDURE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $procedure_indications  XML which contains the details collected from the patient.
    */
    public function getProcedureIndications($pid, $encounter)
    {
        $procedure_indications = '';
        $formTables_details = $this->fetchFields('procedure_note', 'procedure_indications', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $procedure_indications .= "<procedure_indications><item>";
        foreach ($result as $row) {
            $procedure_indications .= xmlEscape(implode(' ', $row));
        }

        $procedure_indications .= "</item></procedure_indications>";

        return $procedure_indications;
    }

    /*
    #***********************************************#
    #                OPERATIVE NOTES                #
    #***********************************************#
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $anesthesia  XML which contains the details collected from the patient.
    */
    public function getAnesthesia($pid, $encounter)
    {
        $anesthesia = '';
        $formTables_details = $this->fetchFields('operative_note', 'anesthesia', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $anesthesia .= "<anesthesia><item>";
        foreach ($result as $row) {
            $anesthesia .= xmlEscape(implode(' ', $row));
        }

        $anesthesia .= "</item></anesthesia>";
        return $anesthesia;
    }

    /*
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $post_operative_diag  XML which contains the details collected from the patient.
    */
    public function getPostoperativeDiag($pid, $encounter)
    {
        $post_operative_diag = '';
        $formTables_details = $this->fetchFields('operative_note', 'post_operative_diagnosis', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $post_operative_diag .= "<post_operative_diag><item>";
        foreach ($result as $row) {
            $post_operative_diag .= xmlEscape(implode(' ', $row));
        }

        $post_operative_diag .= "</item></post_operative_diag>";
        return $post_operative_diag;
    }

    /*
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $pre_operative_diag  XML which contains the details collected from the patient.
    */
    public function getPreOperativeDiag($pid, $encounter)
    {
        $pre_operative_diag = '';
        $formTables_details = $this->fetchFields('operative_note', 'pre_operative_diagnosis', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $pre_operative_diag .= "<pre_operative_diag><item>";
        foreach ($result as $row) {
            $pre_operative_diag .= xmlEscape(implode(' ', $row));
        }

        $pre_operative_diag .= "</item></pre_operative_diag>";
        return $pre_operative_diag;
    }

    /*
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $pre_operative_diag  XML which contains the details collected from the patient.
    */
    public function getEstimatedBloodLoss($pid, $encounter)
    {
        $estimated_blood_loss = '';
        $formTables_details = $this->fetchFields('operative_note', 'procedure_estimated_blood_loss', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $estimated_blood_loss .= "<blood_loss><item>";
        foreach ($result as $row) {
            $estimated_blood_loss .= xmlEscape(implode(' ', $row));
        }

        $estimated_blood_loss .= "</item></blood_loss>";
        return $estimated_blood_loss;
    }

    /*
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $pre_operative_diag  XML which contains the details collected from the patient.
    */
    public function getProcedureFindings($pid, $encounter)
    {
        $procedure_findings = '';
        $formTables_details = $this->fetchFields('operative_note', 'procedure_findings', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $procedure_findings .= "<procedure_findings><item>";
        foreach ($result as $row) {
            $procedure_findings .= xmlEscape(implode(' ', $row));
        }

        $procedure_findings .= "</item><age>" . xmlEscape($this->getAge($pid)) . "</age></procedure_findings>";
        return $procedure_findings;
    }

    /*
    Sub section of OPERATIVE NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $pre_operative_diag  XML which contains the details collected from the patient.
    */
    public function getProcedureSpecimensTaken($pid, $encounter)
    {
        $procedure_specimens = '';
        $formTables_details = $this->fetchFields('operative_note', 'procedure_specimens_taken', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $procedure_specimens .= "<procedure_specimens><item>";
        foreach ($result as $row) {
            $procedure_specimens .= xmlEscape(implode(' ', $row));
        }

        $procedure_specimens .= "</item></procedure_specimens>";
        return $procedure_specimens;
    }

    /*
    #***********************************************#
    #             CONSULTATION NOTES                #
    #***********************************************#
    Sub section of CONSULTATION NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $hp  XML which contains the details collected from the patient.
    */
    public function getHP($pid, $encounter)
    {
        $hp = '';
        $formTables_details = $this->fetchFields('consultation_note', 'history_of_present_illness', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $hp .= "<hp><item>";
        foreach ($result as $row) {
            $hp .= xmlEscape(implode(' ', $row));
        }

        $hp .= "</item></hp>";
        return $hp;
    }

    /*
    Sub section of CONSULTATION NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $physical_exam  XML which contains the details collected from the patient.
    */
    public function getPhysicalExam($pid, $encounter)
    {
        $physical_exam = '';
        $formTables_details = $this->fetchFields('consultation_note', 'physical_exam', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $physical_exam .= "<physical_exam><item>";
        foreach ($result as $row) {
            $physical_exam .= xmlEscape(implode(' ', $row));
        }

        $physical_exam .= "</item></physical_exam>";
        return $physical_exam;
    }

    /*
    #********************************************************#
    #                HISTORY AND PHYSICAL NOTES              #
    #********************************************************#
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $chief_complaint  XML which contains the details collected from the patient.
    */
    public function getChiefComplaint($pid, $encounter)
    {
        $chief_complaint = '';
        $formTables_details = $this->fetchFields('history_physical_note', 'chief_complaint', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $chief_complaint .= "<chief_complaint><item>";
        foreach ($result as $row) {
            $chief_complaint .= xmlEscape(implode(' ', $row));
        }

        $chief_complaint .= "</item></chief_complaint>";
        return $chief_complaint;
    }

    /*
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $general_status  XML which contains the details collected from the patient.
    */
    public function getGeneralStatus($pid, $encounter)
    {
        $general_status = '';
        $formTables_details = $this->fetchFields('history_physical_note', 'general_status', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $general_status .= "<general_status><item>";
        foreach ($result as $row) {
            $general_status .= xmlEscape(implode(' ', $row));
        }

        $general_status .= "</item></general_status>";
        return $general_status;
    }

    /*
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $history_past_illness  XML which contains the details collected from the patient.
    */
    public function getHistoryOfPastIllness($pid, $encounter)
    {
        $history_past_illness = '';
        $formTables_details = $this->fetchFields('history_physical_note', 'hpi_past_med', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $history_past_illness .= "<history_past_illness><item>";
        foreach ($result as $row) {
            $history_past_illness .= xmlEscape(implode(' ', $row));
        }

        $history_past_illness .= "</item></history_past_illness>";
        return $history_past_illness;
    }

    /*
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $review_of_systems  XML which contains the details collected from the patient.
    */
    public function getReviewOfSystems($pid, $encounter)
    {
        $review_of_systems = '';
        $formTables_details = $this->fetchFields('history_physical_note', 'review_of_systems', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $review_of_systems .= "<review_of_systems><item>";
        foreach ($result as $row) {
            $review_of_systems .= xmlEscape(implode(' ', $row));
        }

        $review_of_systems .= "</item></review_of_systems>";
        return $review_of_systems;
    }

    /*
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $vitals  XML which contains the details collected from the patient.
    */
    public function getVitals($pid, $encounter)
    {
        $wherCon = '';
        if ($encounter) {
            $wherCon = "AND fe.encounter = $encounter";
        }

        $vitals = '';
        $query = "SELECT DATE(fe.date) AS date, fv.id, temperature, bpd, bps, head_circ, pulse, height, respiration, BMI_status,  oxygen_saturation, weight, BMI FROM forms AS f
                JOIN form_encounter AS fe ON fe.encounter = f.encounter AND fe.pid = f.pid
                JOIN form_vitals AS fv ON fv.id = f.form_id
                WHERE f.pid = ? AND f.formdir = 'vitals' AND f.deleted=0 $wherCon
                ORDER BY fe.date DESC";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($pid));


        $vitals .= "<vitals_list>";
        foreach ($res as $row) {
            $convWeightValue = number_format($row['weight'] * 0.45359237, 2);
            $convHeightValue = round(number_format($row['height'] * 2.54, 2), 1);
            $convTempValue = round(number_format(($row['temperature'] - 32) * (5 / 9), 1));
            if ($GLOBALS['units_of_measurement'] == 2 || $GLOBALS['units_of_measurement'] == 4) {
                $weight_value = $convWeightValue;
                $weight_unit = 'kg';
                $height_value = $convHeightValue;
                $height_unit = 'cm';
                $temp_value = $convTempValue;
                $temp_unit = 'Cel';
            } else {
                $temp = US_weight($row['weight'], 1);
                $tempArr = explode(" ", $temp);
                $weight_value = $tempArr[0];
                $weight_unit = 'lb';
                $height_value = $row['height'];
                $height_unit = 'in';
                $temp_value = $row['temperature'];
                $temp_unit = 'degF';
            }

            $vitals .= "<vitals>
            <extension>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'])) . "</extension>
            <sha_extension>" . xmlEscape("c6f88321-67ad-11db-bd13-0800200c9a66") . "</sha_extension>
            <date>" . xmlEscape($this->date_format($row['date'])) . "</date>
            <effectivetime>" . xmlEscape(preg_replace('/-/', '', $row['date'])) . "000000</effectivetime>
            <temperature>" . xmlEscape($temp_value) . "</temperature>
            <unit_temperature>" . xmlEscape($temp_unit) . "</unit_temperature>
            <extension_temperature>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'temperature')) . "</extension_temperature>
            <bpd>" . xmlEscape(($row['bpd'] ?: 0)) . "</bpd>
            <extension_bpd>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'bpd')) . "</extension_bpd>
            <bps>" . xmlEscape(($row['bps'] ?: 0)) . "</bps>
            <extension_bps>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'bps')) . "</extension_bps>
            <head_circ>" . xmlEscape(($row['head_circ'] ?: 0)) . "</head_circ>
            <extension_head_circ>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'head_circ')) . "</extension_head_circ>
            <pulse>" . xmlEscape(($row['pulse'] ?: 0)) . "</pulse>
            <extension_pulse>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'pulse')) . "</extension_pulse>
            <height>" . xmlEscape($height_value) . "</height>
            <extension_height>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'height')) . "</extension_height>
            <unit_height>" . xmlEscape($height_unit) . "</unit_height>
            <oxygen_saturation>" . xmlEscape(($row['oxygen_saturation'] ?: 0)) . "</oxygen_saturation>
            <extension_oxygen_saturation>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'oxygen_saturation')) . "</extension_oxygen_saturation>
            <breath>" . xmlEscape(($row['respiration'] ?: 0)) . "</breath>
            <extension_breath>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'breath')) . "</extension_breath>
            <weight>" . xmlEscape($weight_value) . "</weight>
            <extension_weight>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'weight')) . "</extension_weight>
            <unit_weight>" . xmlEscape($weight_unit) . "</unit_weight>
            <BMI>" . xmlEscape(($row['BMI'] ?: 0)) . "</BMI>
            <extension_BMI>" . xmlEscape(base64_encode($_SESSION['site_id'] . $row['id'] . 'BMI')) . "</extension_BMI>
            <BMI_status>" . xmlEscape(($row['BMI_status'] ?: 0)) . "</BMI_status>
            </vitals>";
        }

        $vitals .= "</vitals_list>";
        return $vitals;
    }

    /*
    Sub section of HISTORY AND PHYSICAL NOTES in CCDA.
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $social_history  XML which contains the details collected from the patient.
    */
    public function getSocialHistory($pid, $encounter)
    {
        $social_history = '';
        $arr = array(
            'alcohol' => '160573003',
            'drug' => '363908000',
            'employment' => '364703007',
            'exercise' => '256235009',
            'other_social_history' => '228272008',
            'diet' => '364393001',
            'smoking' => '229819007',
            'toxic_exposure' => '425400000'
        );
        $arr_status = array(
            'currenttobacco' => 'Current',
            'quittobacco' => 'Quit',
            'nevertobacco' => 'Never',
            'currentalcohol' => 'Current',
            'quitalcohol' => 'Quit',
            'neveralcohol' => 'Never'
        );

        $snomeds_status = array(
            'currenttobacco' => 'completed',
            'quittobacco' => 'completed',
            'nevertobacco' => 'completed',
            'not_applicabletobacco' => 'completed'
        );

        $snomeds = array(
            '1' => '449868002',
            '2' => '428041000124106',
            '3' => '8517006',
            '4' => '266919005',
            '5' => '77176002'
        );

        $alcohol_status = array(
            'currentalcohol' => 'completed',
            'quitalcohol' => 'completed',
            'neveralcohol' => 'completed'
        );

        $alcohol_status_codes = array(
            'currentalcohol' => '11',
            'quitalcohol' => '22',
            'neveralcohol' => '33'
        );

        $query = "SELECT id, tobacco, alcohol, exercise_patterns, recreational_drugs FROM history_data WHERE pid=? ORDER BY id DESC LIMIT 1";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($pid));

        $social_history .= "<social_history>";
        foreach ($res as $row) {
            $tobacco = explode('|', $row['tobacco']);
            $status_code = (new CarecoordinationTable())->getListCodes($tobacco[3], 'smoking_status');
            $status_code = str_replace("SNOMED-CT:", "", $status_code);
            $social_history .= "<history_element>
                                  <extension>" . xmlEscape(base64_encode('smoking' . $_SESSION['site_id'] . $row['id'])) . "</extension>
                                  <sha_extension>" . xmlEscape("9b56c25d-9104-45ee-9fa4-e0f3afaa01c1") . "</sha_extension>
                                  <element>" . xmlEscape('Smoking') . "</element>
                                  <description>" . xmlEscape((new CarecoordinationTable())->getListTitle($tobacco[3], 'smoking_status')) . "</description>
                                  <status_code>" . xmlEscape(($status_code ? $status_code : 0)) . "</status_code>
                                  <status>" . xmlEscape(($snomeds_status[$tobacco[1]] ? $snomeds_status[$tobacco[1]] : 'NULL')) . "</status>
                                  <date>" . ($tobacco[2] ? xmlEscape($this->date_format($tobacco[2])) : 0) . "</date>
                                  <date_formatted>" . ($tobacco[2] ? xmlEscape(preg_replace('/-/', '', $tobacco[2])) : 0) . "</date_formatted>
                                  <code>" . xmlEscape(($arr['smoking'] ? $arr['smoking'] : 0)) . "</code>
                            </history_element>";
            $alcohol = explode('|', $row['alcohol']);
            $social_history .= "<history_element>
                                  <extension>" . xmlEscape(base64_encode('alcohol' . $_SESSION['site_id'] . $row['id'])) . "</extension>
                                  <sha_extension>" . xmlEscape("37f76c51-6411-4e1d-8a37-957fd49d2cef") . "</sha_extension>
                                  <element>" . xmlEscape('Alcohol') . "</element>
                                  <description>" . xmlEscape($alcohol[0]) . "</description>
                                  <status_code>" . xmlEscape(($alcohol_status_codes[$alcohol[1]] ? $alcohol_status_codes[$alcohol[1]] : 0)) . "</status_code>
                                  <status>" . xmlEscape(($alcohol_status[$alcohol[1]] ? $alcohol_status[$alcohol[1]] : 'completed')) . "</status>
                                  <date>" . ($alcohol[2] ? xmlEscape($this->date_format($alcohol[2])) : 0) . "</date>
                                  <date_formatted>" . ($alcohol[2] ? xmlEscape(preg_replace('/-/', '', $alcohol[2])) : 0) . "</date_formatted>
                                  <code>" . xmlEscape($arr['alcohol']) . "</code>
                            </history_element>";
        }

        $social_history .= "</social_history>";
        return $social_history;
    }

    /*
    #********************************************************#
    #                  UNSTRUCTURED DOCUMENTS                #
    #********************************************************#
    */
    public function getUnstructuredDocuments($pid, $encounter)
    {
        $image = '';
        $formTables_details = $this->fetchFields('unstructured_document', 'unstructured_doc', 1);
        $result = $this->fetchFormValues($pid, $encounter, $formTables_details);

        $image .= "<document>";
        foreach ($result as $row) {
            foreach ($row as $key => $value) {
                $image .= "<item>";
                $image .= "<type>" . xmlEscape($row[$key][1]) . "</type>";
                $image .= "<content>" . xmlEscape($row[$key][0]) . "</content>";
                $image .= "</item>";
            }
        }

        $image .= "</document>";
        return $image;
    }

    public function getDetails($field_name)
    {
        if ($field_name == 'hie_custodian_id') {
            $query = "SELECT f.name AS organization, f.street, f.city, f.state, f.postal_code AS zip, f.phone AS phonew1
			FROM facility AS f
			JOIN modules AS mo ON mo.mod_directory='Carecoordination'
			JOIN module_configuration AS conf ON conf.field_value=f.id AND mo.mod_id=conf.module_id
			WHERE conf.field_name=?";
        } else {
            $query = "SELECT u.title, u.fname, u.mname, u.lname, u.npi, u.street, u.city, u.state, u.zip, CONCAT_WS(' ','',u.phonew1) AS phonew1, u.organization, u.specialty, conf.field_name, mo.mod_name, lo.title as  physician_type, SUBSTRING(lo.codes, LENGTH('SNOMED-CT:')+1, LENGTH(lo.codes)) as  physician_type_code
            FROM users AS u
	    LEFT JOIN list_options AS lo ON lo.list_id = 'physician_type' AND lo.option_id = u.physician_type
            JOIN modules AS mo ON mo.mod_directory='Carecoordination'
            JOIN module_configuration AS conf ON conf.field_value=u.id AND mo.mod_id=conf.module_id
            WHERE conf.field_name=?";
        }

        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($field_name));
        foreach ($res as $result) {
            return $result;
        }
    }

    /*
    Get the Age of a patient
    * @param    int     $pid    Patient Internal Identifier.

    * return    int     $age    Age of a patient will be returned
    */
    public function getAge($pid, $date = null)
    {
        if ($date != '') {
            $date = $date;
        } else {
            $date = date('Y-m-d H:i:s');
        }

        $age = 0;
        $query = "select ROUND(DATEDIFF('$date',DOB)/365.25) AS age from patient_data where pid= ? ";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($pid));

        foreach ($res as $row) {
            $age = $row['age'];
        }

        return $age;
    }

    public function getRepresentedOrganization()
    {
        $query = "select * from facility where primary_business_entity = ?";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array(1));

        $records = array();
        foreach ($res as $row) {
            $records = $row;
        }

        return $records;
    }

    /*Get the list of items mapped to a particular CCDA section

    * @param        $ccda_component     CCDA component
    * @param        $ccda_section       CCDA section of the above component
    * @param        $user_id            1
    * @return       $ret                Array containing the list of items mapped in a particular CCDA section.
    */
    public function fetchFields($ccda_component, $ccda_section, $user_id)
    {
        $form_type = $table_name = $field_names = '';
        $query = "select * from ccda_table_mapping
            left join ccda_field_mapping as ccf on ccf.table_id = ccda_table_mapping.id
            where ccda_component = ? and ccda_component_section = ? and user_id = ? and deleted = 0";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($ccda_component, $ccda_section, $user_id));
        $field_names_type3 = '';
        $ret = array();
        $field_names_type1 = '';
        $field_names_type2 = '';
        foreach ($res as $row) {
            $form_type = $row['form_type'];
            $table_name = $row['form_table'];
            $form_dir = $row['form_dir'];
            if ($form_type == 1) {
                if ($field_names_type1) {
                    $field_names_type1 .= ',';
                }

                $field_names_type1 .= $row['ccda_field'];
                $ret[$row['ccda_component_section'] . "_" . $form_dir] = array($form_type, $table_name, $form_dir, $field_names_type1);
            } elseif ($form_type == 2) {
                if ($field_names_type2) {
                    $field_names_type2 .= ',';
                }

                $field_names_type2 .= $row['ccda_field'];
                $ret[$row['ccda_component_section'] . "_" . $form_dir] = array($form_type, $table_name, $form_dir, $field_names_type2);
            } elseif ($form_type == 3) {
                if ($field_names_type3) {
                    $field_names_type3 .= ',';
                }

                $field_names_type3 .= $row['ccda_field'];
                $ret[$row['ccda_component_section'] . "_" . $form_dir] = array($form_type, $table_name, $form_dir, $field_names_type3);
            }
        }

        return $ret;
    }

    /*Fetch the form values

    * @param        $pid
    * @param        $encounter
    * @param        $formTables
    * @return       $res            Array of forms values of a single section
    */
    public function fetchFormValues($pid, $encounter, $formTables)
    {
        if (empty($encounter)) {
            return "";
        }
        $res = array();
        $count_folder = 0;
        foreach ($formTables as $formTables_details) {
            /***************Fetching the form id for the patient***************/
            $query = "select form_id,encounter from forms where pid = ? and formdir = ? AND deleted=0";
            $appTable = new ApplicationTable();
            $form_ids = $appTable->zQuery($query, array($pid, $formTables_details[2]));
            /***************Fetching the form id for the patient***************/

            if ($formTables_details[0] == 1) {//Fetching the values from an HTML form
                if (!$formTables_details[1]) {//Fetching the complete form
                    foreach ($form_ids as $row) {//Fetching the values of each forms
                        foreach ($row as $key => $value) {
                            ob_start();
                            if (file_exists($GLOBALS['fileroot'] . '/interface/forms/' . $formTables_details[2] . '/report.php')) {
                                include_once($GLOBALS['fileroot'] . '/interface/forms/' . $formTables_details[2] . '/report.php');
                                call_user_func($formTables_details[2] . "_report", $pid, $encounter, 2, $value);
                            }

                            $res[0][$value] = ob_get_clean();
                        }
                    }
                } else {//Fetching a single field from the table
                    $primary_key = '';
                    $query = "SHOW INDEX FROM ? WHERE Key_name='PRIMARY'";
                    $appTable = new ApplicationTable();
                    $res_primary = $appTable->zQuery($query, array($formTables_details[1]));
                    foreach ($res_primary as $row_primary) {
                        $primary_key = $row_primary['Column_name'];
                    }

                    unset($res_primary);

                    $query = "select " . $formTables_details[3] . " from " . $formTables_details[1] . "
                    join forms as f on f.pid=? AND f.encounter=? AND f.form_id=" . $formTables_details[1] . "." . $primary_key . " AND f.formdir=?
                    where 1 = 1 ";
                    $appTable = new ApplicationTable();
                    $result = $appTable->zQuery($query, array($pid, $encounter, $formTables_details[2]));

                    foreach ($result as $row) {
                        foreach ($row as $key => $value) {
                            $res[0][$key] .= trim($value);
                        }
                    }
                }
            } elseif ($formTables_details[0] == 2) {//Fetching the values from an LBF form
                if (!$formTables_details[1]) {//Fetching the complete LBF
                    foreach ($form_ids as $row) {
                        foreach ($row as $key => $value) {
                            //This section will be used to fetch complete LBF. This has to be completed. We are working on this.
                        }
                    }
                } elseif (!$formTables_details[3]) {//Fetching the complete group from an LBF
                    foreach ($form_ids as $row) {//Fetching the values of each encounters
                        foreach ($row as $key => $value) {
                            ob_start();
                            ?>
                            <table>
                                <?php
                                display_layout_rows_group_new($formTables_details[2], '', '', $pid, $value, array($formTables_details[1]), '');
                                ?>
                            </table>
                            <?php
                            $res[0][$value] = ob_get_clean();
                        }
                    }
                } else {
                    $formid_list = "";
                    foreach ($form_ids as $row) {//Fetching the values of each forms
                        foreach ($row as $key => $value) {
                            if ($formid_list) {
                                $formid_list .= ',';
                            }

                            $formid_list .= $value;
                        }
                    }

                    $formid_list = $formid_list ? $formid_list : "''";
                    $lbf = "lbf_data";
                    $filename = "{$GLOBALS['srcdir']}/" . $formTables_details[2] . "/" . $formTables_details[2] . "_db.php";
                    if (file_exists($filename)) {
                        include_once($filename);
                    }

                    $field_ids = explode(',', $formTables_details[3]);
                    $fields_str = '';
                    foreach ($field_ids as $key => $value) {
                        if ($fields_str != '') {
                            $fields_str .= ",";
                        }

                        $fields_str .= "'$value'";
                    }

                    $query = "select * from " . $lbf . "
                    join forms as f on f.pid = ? AND f.form_id = " . $lbf . ".form_id AND f.formdir = ? AND " . $lbf . ".field_id IN (" . $fields_str . ")
                    where deleted = 0";
                    $appTable = new ApplicationTable();
                    $result = $appTable->zQuery($query, array($pid, $formTables_details[2]));

                    foreach ($result as $row) {
                        preg_match('/\.$/', trim($row['field_value']), $matches);
                        if (count($matches) == 0) {
                            $row['field_value'] .= ". ";
                        }

                        $res[0][$row['field_id']] .= $row['field_value'];
                    }
                }
            } elseif ($formTables_details[0] == 3) {//Fetching documents from mapped folders
                $query = "SELECT c.id, c.name, d.id AS document_id, d.type, d.mimetype, d.url, d.docdate
                FROM categories AS c, documents AS d, categories_to_documents AS c2d
                WHERE c.id = ? AND c.id = c2d.category_id AND c2d.document_id = d.id AND d.foreign_id = ?";

                $appTable = new ApplicationTable();
                $result = $appTable->zQuery($query, array($formTables_details[2], $pid));

                foreach ($result as $row_folders) {
                    $r = \Documents\Plugin\Documents::getDocument($row_folders['document_id']);
                    $res[0][$count_folder][0] = base64_encode($r);
                    $res[0][$count_folder][1] = $row_folders['mimetype'];
                    $res[0][$count_folder][2] = $row_folders['url'];
                    $count_folder++;
                }
            }
        }

        return $res;
    }

    /*
    * Retrive the saved settings of the module from database
    *
    * @param    string      $module_directory       module directory name
    * @param    string      $field_name             field name as in the module_settings table
    */
    public function getSettings($module_directory, $field_name)
    {
        $query = "SELECT mo_conf.field_value FROM modules AS mo
        LEFT JOIN module_configuration AS mo_conf ON mo_conf.module_id = mo.mod_id
        WHERE mo.mod_directory = ? AND mo_conf.field_name = ?";
        $appTable = new ApplicationTable();
        $result = $appTable->zQuery($query, array($module_directory, $field_name));
        foreach ($result as $row) {
            return $row['field_value'];
        }
    }

    /*
    * Get the encounters in a particular date
    *
    * @param    Date    $date           Date format yyyy-mm-dd
    * $return   Array   $date_list      List of encounter in the given date.
    */
    public function getEncounterDate($date)
    {
        $date_list = array();
        $query = "select pid, encounter from form_encounter where date between ? and ?";
        $appTable = new ApplicationTable();
        $result = $appTable->zQuery($query, array($date, $date));

        $count = 0;
        foreach ($result as $row) {
            $date_list[$count]['pid'] = $row['pid'];
            $date_list[$count]['encounter'] = $row['encounter'];
            $count++;
        }

        return $date_list;
    }

    /*
    * Sign Off an encounter
    *
    * @param    integer     $pid
    * @param    integer     $encounter
    * @return   array       $forms          List of locked forms
    */
    public function signOff($pid, $encounter)
    {
        /*Saving Demographics to locked data*/
        $query_patient_data = "SELECT * FROM patient_data WHERE pid = ?";
        $appTable = new ApplicationTable();
        $result_patient_data = $appTable->zQuery($query_patient_data, array($pid));
        foreach ($result_patient_data as $row_patient_data) {
        }

        $query_dem = "SELECT field_id FROM layout_options WHERE form_id = ?";
        $appTable = new ApplicationTable();
        $result_dem = $appTable->zQuery($query_dem, array('DEM'));

        foreach ($result_dem as $row_dem) {
            $query_insert_patient_data = "INSERT INTO combination_form_locked_data SET pid = ?, encounter = ?, form_dir = ?, field_name = ?, field_value = ?";
            $appTable = new ApplicationTable();
            $result_dem = $appTable->zQuery($query_insert_patient_data, array($pid, $encounter, 'DEM', $row_dem['field_id'], $row_patient_data[$row_dem['field_id']]));
        }

        /*************************************/

        $query_saved_forms = "SELECT formid FROM combined_encountersaved_forms WHERE pid = ? AND encounter = ?";
        $appTable = new ApplicationTable();
        $result_saved_forms = $appTable->zQuery($query_saved_forms, array($pid, $encounter));
        $count = 0;
        foreach ($result_saved_forms as $row_saved_forms) {
            $form_dir = '';
            $form_type = 0;
            $form_id = 0;
            $temp = explode('***', $row_saved_forms['formid']);
            if ($temp[1] == 1) { //Fetch HTML form id from the Combination form template
                $form_type = 0;
                $form_dir = $temp[0];
            } else { //Fetch LBF form from the Combination form template
                $temp_1 = explode('*', $temp[1]);
                if ($temp_1[1] == 1) { //Complete LBF in Combination form
                    $form_type = 1;
                    $form_dir = $temp[0];
                } elseif ($temp_1[1] == 2) { //Particular section from LBF in Combination form
                    $temp_2 = explode('|', $temp[0]);
                    $form_type = 1;
                    $form_dir = $temp_2[0];
                }
            }

            /*Fetch form id from the concerned tables*/
            if ($form_dir == 'HIS') { //Fetching History form id
                $query_form_id = "SELECT MAX(id) AS form_id FROM history_data WHERE pid = ?";
                $appTable = new ApplicationTable();
                $result_form_id = $appTable->zQuery($query_form_id, array($pid));
            } else { //Fetching normal form id
                $query_form_id = "select form_id from forms where pid = ? and encounter = ? and formdir = ?";
                $appTable = new ApplicationTable();
                $result_form_id = $appTable->zQuery($query_form_id, array($pid, $encounter, $form_dir));
            }

            foreach ($result_form_id as $row_form_id) {
                $form_id = $row_form_id['form_id'];
            }

            /****************************************/
            $forms[$count]['formdir'] = $form_dir;
            $forms[$count]['formtype'] = $form_type;
            $forms[$count]['formid'] = $form_id;
            $this->lockedthisform($pid, $encounter, $form_dir, $form_type, $form_id);
            $count++;
        }

        return $forms;
    }

    /*
    * Lock a component in combination form
    *
    * @param    integer     $pid
    * @param    integer     $encounter
    * @param    integer     $formdir        Form directory
    * @param    integer     $formtype       Form type, 0 => HTML, 1 => LBF
    * @param    integer     $formid         Saved form id from forms table
    *
    * @return   None
    */
    public function lockedthisform($pid, $encounter, $formdir, $formtype, $formid)
    {
        $query = "select count(*) as count from combination_form where pid = ? and encounter = ? and form_dir = ? and form_type = ? and form_id = ?";
        $appTable = new ApplicationTable();
        $result = $appTable->zQuery($query, array($pid, $encounter, $formdir, $formtype, $formid));
        foreach ($result as $count) {
        }

        if ($count['count'] == 0) {
            $query_insert = "INSERT INTO combination_form SET pid = ?, encounter = ?, form_dir = ?, form_type = ?, form_id = ?";
            $appTable = new ApplicationTable();
            $result = $appTable->zQuery($query_insert, array($pid, $encounter, $formdir, $formtype, $formid));
        }
    }

    /*
    * Return the list of CCDA components
    *
    * @param    $type
    * @return   Array       $components
    */
    public function getCCDAComponents($type)
    {
        $components = array();
        $query = "select * from ccda_components where ccda_type = ?";
        $appTable = new ApplicationTable();
        $result = $appTable->zQuery($query, array($type));

        foreach ($result as $row) {
            $components[$row['ccda_components_field']] = $row['ccda_components_name'];
        }

        return $components;
    }

    /*
    * Store the status of the CCDA sent to HIE
    *
    * @param    integer     $pid
    * @param    integer     $encounter
    * @param    integer     $content
    * @param    integer     $time
    * @param    integer     $status
    * @return   None
    */
    public function logCCDA($pid, $encounter, $content, $time, $status, $user_id, $view = 0, $transfer = 0, $emr_transfer = 0)
    {
        $content = base64_decode($content);
        $file_path = '';
        $docid = '';
        $revid = '';
        if ($GLOBALS['document_storage_method'] == 1) {
            $couch = new CouchDB();
            $docid = $couch->createDocId('ccda');
            $binaryUuid = UuidRegistry::uuidToBytes($docid);
            if ($GLOBALS['couchdb_encryption']) {
                $encrypted = 1;
                $cryptoGen = new CryptoGen();
                $resp = $couch->save_doc(['_id' => $docid, 'data' => $cryptoGen->encryptStandard($content, null, 'database')]);
            } else {
                $encrypted = 0;
                $resp = $couch->save_doc(['_id' => $docid, 'data' => base64_encode($content)]);
            }
            $docid = $resp->id;
            $revid = $resp->rev;
        } else {
            $binaryUuid = (new UuidRegistry(['table_name' => 'ccda']))->createUuid();
            $file_name = UuidRegistry::uuidToString($binaryUuid);
            $file_path = $GLOBALS['OE_SITE_DIR'] . '/documents/' . $pid . '/CCDA';
            if (!is_dir($file_path)) {
                if (!mkdir($file_path, 0777, true) && !is_dir($file_path)) {
                    // php Exception extends RunTimeException
                    throw new Exception(sprintf('Directory "%s" was not created', $file_path));
                }
            }

            $fccda = fopen($file_path . "/" . $file_name, "w");
            if ($GLOBALS['drive_encryption']) {
                $encrypted = 1;
                $cryptoGen = new CryptoGen();
                fwrite($fccda, $cryptoGen->encryptStandard($content, null, 'database'));
            } else {
                $encrypted = 0;
                fwrite($fccda, $content);
            }
            fclose($fccda);
            $file_path = $file_path . "/" . $file_name;
        }

        $query = "insert into ccda (`uuid`, `pid`, `encounter`, `ccda_data`, `time`, `status`, `user_id`, `couch_docid`, `couch_revid`, `hash`, `view`, `transfer`, `emr_transfer`, `encrypted`) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $hash = hash('sha3-512', $content);
        $appTable = new ApplicationTable();
        $result = $appTable->zQuery($query, array($binaryUuid, $pid, $encounter, $file_path, $time, $status, $user_id, $docid, $revid, $hash, $view, $transfer, $emr_transfer, $encrypted));
        return $moduleInsertId = $result->getGeneratedValue();
    }

    public function getCcdaLogDetails($logID = 0)
    {
        $query_ccda_log = "SELECT pid, encounter, ccda_data, time, status, user_id, couch_docid, couch_revid, view, transfer,emr_transfer FROM ccda WHERE id = ?";
        $appTable = new ApplicationTable();
        $res_ccda_log = $appTable->zQuery($query_ccda_log, array($logID));
        return $res_ccda_log->current();
    }

    /*
    * Convert date from database format to required format
    *
    * @param    String      $date       Date from database (format: YYYY-MM-DD)
    * @param    String      $format     Required date format
    *
    * @return   String      $formatted_date New formatted date
    */
    public function date_format($date, $format = null)
    {
        if (!$date) {
            return;
        }

        $format = $format ? $format : 'm/d/y';
        $temp = explode(' ', $date); //split using space and consider the first portion, incase of date with time
        $date = $temp[0];
        $date = str_replace('/', '-', $date);
        $arr = explode('-', $date);

        if ($format == 'm/d/y') {
            $formatted_date = $arr[1] . "/" . $arr[2] . "/" . $arr[0];
        }

        $formatted_date = $temp[1] ? $formatted_date . " " . $temp[1] : $formatted_date; //append the time, if exists, with the new formatted date
        return $formatted_date;
    }

    /*
    * Generate CODE for medication, allergies etc.. if the code is not present by default.
    * The code is generated from the text that we give for medications or allergies.
    *
    * The text is encrypted using SHA1() and the string is parsed. Alternate letters from the SHA1 string is fetched
    * and the result is again parsed. We again take the alternate letters from the string. This is done twice to reduce
    * duplicate codes beign generated from this function.
    *
    * @param    String      Code text
    *
    * @return   String      Code
    */
    public function generate_code($code_text)
    {
        $rx = sqlQuery("Select drug_code From drugs Where name = ?", array("$code_text"));
        if (!empty($rx)) {
            return $rx['drug_code'];
        }
        $encrypted = sha1($code_text);
        $code = '';
        for ($i = 0, $iMax = strlen($encrypted); $i <= $iMax;) {
            $code .= $encrypted[$i];
            $i = $i + 2;
        }

        $encrypted = $code;
        $code = '';
        for ($i = 0, $iMax = strlen($encrypted); $i <= $iMax;) {
            $code .= $encrypted[$i];
            $i = $i + 2;
        }

        $code = strtoupper(substr($code, 0, 6));
        return $code;
    }

    public function getProviderId($pid)
    {
        $appTable = new ApplicationTable();
        $query = "SELECT providerID FROM patient_data WHERE `pid`  = ?";
        $result = $appTable->zQuery($query, array($pid));
        $row = $result->current();
        return $row['providerID'];
    }

    public function getUserDetails($uid)
    {
        $query = "SELECT u.title,npi,fname,mname,lname,street,city,state,zip,CONCAT_WS(' ','',phonew1) AS phonew1, lo.title as  physician_type, facility As organization, taxonomy, lous.title as taxonomy_desc, specialty, SUBSTRING(lo.codes, LENGTH('SNOMED-CT:')+1, LENGTH(lo.codes)) as physician_type_code FROM users as u
        LEFT JOIN list_options AS lo ON lo.list_id = 'physician_type' AND lo.option_id = u.physician_type
        LEFT JOIN list_options AS lous ON lous.list_id = 'us-core-provider-specialty' AND lous.option_id = u.taxonomy
        WHERE `id` = ?";
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, array($uid));
        foreach ($res as $result) {
            return $result;
        }
    }

    /**
     * Checks to see if the snomed codes are installed and we can then query against them.
     */
    private function is_snomed_codes_installed(ApplicationTable $appTable)
    {
        $codes_installed = false;
        // this throws an exception... which is sad
        // TODO: is there a better way to know if the snomed codes are installed instead of using this method?
        // we set $error=false or else it will display on the screen, which seems counterintuitive... it also supresses the exception
        $result = $appTable->zQuery("Describe `sct_descriptions`", $params = '', $log = true, $error = false);
        if ($result !== false) { // will return false if there is an error
            $codes_installed = true;
        }


        return $codes_installed;
    }

    /*
    * get details from care plan form
    * @param    int     $pid           Patient Internal Identifier.
    * @param    int     $encounter     Current selected encounter.

    * return    string  $planofcare  XML which contains the details collected from the patient.
    */
    public function getPlanOfCare($pid, $encounter)
    {
        $wherCon = '';
        $appTable = new ApplicationTable();
        if ($encounter) {
            $query = "SELECT form_id, encounter FROM forms  WHERE pid = ? AND formdir = ? AND deleted = 0 ORDER BY date DESC LIMIT 1";
            $result = $appTable->zQuery($query, array($pid, 'care_plan'));
            foreach ($result as $row) {
                $form_id = $row['form_id'];
            }

            if ($form_id) {
                $wherCon = "AND f.form_id = '" . add_escape_custom($form_id) . "'";
            }
        }

        UuidRegistry::createMissingUuidsForTables(['lists']);

        $query = "SELECT 'care_plan' AS source,fcp.encounter,fcp.code,fcp.codetext,fcp.description,fcp.date,l.`notes` AS moodCode,fcp.care_plan_type AS care_plan_type,fcp.note_related_to as note_issues
            FROM forms AS f
            LEFT JOIN form_care_plan AS fcp ON fcp.id = f.form_id
            LEFT JOIN codes AS c ON c.code = fcp.code
            LEFT JOIN code_types AS ct ON c.`code_type` = ct.ct_id
            LEFT JOIN `list_options` l ON l.`option_id` = fcp.`care_plan_type` AND l.`list_id`=?
            WHERE f.pid = ? AND f.formdir = ? AND f.deleted = ? $wherCon
            UNION
            SELECT 'referral' AS source,'' encounter,'' AS CODE,'' AS codetext,CONCAT_WS(', ',l1.field_value,CONCAT_WS(' ',u.fname,u.lname),CONCAT('Tel:',u.phonew1),u.street,u.city,CONCAT_WS(' ',u.state,u.zip),CONCAT('Schedule Date: ',l2.field_value)) AS description,l2.field_value AS DATE,'' moodCode,'' care_plan_type, '' note_issues
            FROM transactions AS t
            LEFT JOIN lbt_data AS l1 ON l1.form_id=t.id AND l1.field_id = 'body'
            LEFT JOIN lbt_data AS l2 ON l2.form_id=t.id AND l2.field_id = 'refer_date'
            LEFT JOIN lbt_data AS l3 ON l3.form_id=t.id AND l3.field_id = 'refer_to'
            LEFT JOIN users AS u ON u.id = l3.field_value
            WHERE t.pid = ?";
        $res = $appTable->zQuery($query, ['Plan_of_Care_Type', $pid, 'care_plan', 0, $pid]);
        $status = 'Pending';
        $status_entry = 'active';
        $planofcare = '<planofcare>';
        $goals = '<goals>';
        $concerns = '<health_concerns>';
        foreach ($res as $row) {
            $row['description'] = preg_replace("/\{\|([^\]]*)\|}/", '', $row['description']);
            $tmp = explode(":", $row['code']);
            $code_type = $tmp[0];
            $code = $tmp[1];
            if ($row['source'] === 'referral') {
                $row['care_plan_type'] = 'referral';
            }
            if ($row['care_plan_type'] === 'health_concern') {
                $issue_uuid = "<issues>\n";
                if (!empty($row['note_issues'])) {
                    $issues = json_decode($row['note_issues'], true);
                    foreach ($issues as $issue) {
                        $q = "Select uuid from lists Where id = ?";
                        $uuid = sqlQuery($q, array($issue))['uuid'];
                        if (empty($uuid)) {
                            continue;
                        }
                        $uuid_problem = UuidRegistry::uuidToString($uuid);
                        $issue_uuid .= "<issue_uuid>" . xmlEscape($uuid_problem) . "</issue_uuid>\n";
                    }
                }
                $concerns .= "<concern>" .
                $issue_uuid . "</issues>" .
                "<encounter>" . xmlEscape($row['encounter']) . "</encounter>
                <extension>" . xmlEscape(base64_encode($row['form_id'] . $row['code'])) . "</extension>
                <sha_extension>" . xmlEscape($this->formatUid($row['form_id'] . $row['description'])) . "</sha_extension>
                <text>" . xmlEscape($row['date'] . " " . $row['description']) . '</text>
                <code>' . xmlEscape($code) . '</code>
                <code_type>' . xmlEscape($code_type) . '</code_type>
                <code_text>' . xmlEscape($row['codetext']) . '</code_text>
                <date>' . xmlEscape($row['date']) . '</date>
                <date_formatted>' . xmlEscape(str_replace("-", '', $row['date'])) . '</date_formatted>
                </concern>';
            }
            if ($row['care_plan_type'] === 'goal') {
                $goals .= '<item>
                <extension>' . xmlEscape(base64_encode($row['form_id'] . $row['code'])) . '</extension>
                <sha_extension>' . xmlEscape($this->formatUid($row['form_id'] . $row['description'])) . '</sha_extension>
                <care_plan_type>' . xmlEscape($row['care_plan_type']) . '</care_plan_type>
                <encounter>' . xmlEscape($row['encounter']) . '</encounter>
                <code>' . xmlEscape($code) . '</code>
                <code_text>' . xmlEscape($row['codetext']) . '</code_text>
                <description>' . xmlEscape($row['description']) . '</description>
                <date>' . xmlEscape($row['date']) . '</date>
                <date_formatted>' . xmlEscape(str_replace("-", '', $row['date'])) . '</date_formatted>
                <status>' . xmlEscape($status) . '</status>
                <status_entry>' . xmlEscape($status_entry) . '</status_entry>
                <code_type>' . xmlEscape($code_type) . '</code_type>
                <moodCode>' . xmlEscape($row['moodCode']) . '</moodCode>
                </item>';
            } elseif ($row['care_plan_type'] !== 'health_concern') {
                $planofcare .= '<item>
                <extension>' . xmlEscape(base64_encode($row['form_id'] . $row['code'])) . '</extension>
                <sha_extension>' . xmlEscape($this->formatUid($row['form_id'] . $row['description'])) . '</sha_extension>
                <care_plan_type>' . xmlEscape($row['care_plan_type']) . '</care_plan_type>
                <encounter>' . xmlEscape($row['encounter']) . '</encounter>
                <code>' . xmlEscape($code) . '</code>
                <code_text>' . xmlEscape($row['codetext']) . '</code_text>
                <description>' . xmlEscape($row['description']) . '</description>
                <date>' . xmlEscape($row['date']) . '</date>
                <date_formatted>' . xmlEscape(str_replace("-", '', $row['date'])) . '</date_formatted>
                <status>' . xmlEscape($status) . '</status>
                <status_entry>' . xmlEscape($status_entry) . '</status_entry>
                <code_type>' . xmlEscape($code_type) . '</code_type>
                <moodCode>' . xmlEscape($row['moodCode']) . '</moodCode>
                </item>';
            }
        }

        $planofcare .= '</planofcare>';
        $goals .= '</goals>';
        $concerns .= '</health_concerns>';
        return $planofcare . $goals . $concerns;
    }

    /*
   * get details from functional and cognitive status form
   * @param    int     $pid           Patient Internal Identifier.
   * @param    int     $encounter     Current selected encounter.

   * return    string  $functional_cognitive  XML which contains the details collected from the patient.
   */
    public function getFunctionalCognitiveStatus($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " f.encounter = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $functional_status = '<functional_status>';
        $cognitive_status = '<mental_status>';
        $query = "SELECT ffcs.* FROM forms AS f
                LEFT JOIN form_functional_cognitive_status AS ffcs ON ffcs.id = f.form_id
                WHERE $wherCon f.pid = ? AND f.formdir = ? AND f.deleted = ?";
        array_push($sqlBindArray, $pid, 'functional_cognitive_status', 0);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);

        foreach ($res as $row) {
            if ($row['activity'] == 1) {
                $cognitive_status .= '<item>
        <code>' . xmlEscape(($row['code'] ?: '')) . '</code>
        <code_text>' . xmlEscape(($row['codetext'] ?: '')) . '</code_text>
        <description>' . xmlEscape($row['date'] . ' ' . $row['description']) . '</description>
        <date>' . xmlEscape($row['date']) . '</date>
        <date_formatted>' . xmlEscape(str_replace("-", '', $row['date'])) . '</date_formatted>
        <status>' . xmlEscape('completed') . '</status>
        <age>' . xmlEscape($this->getAge($pid)) . '</age>
        </item>';
            } else {
                $functional_status .= '<item>
        <code>' . xmlEscape(($row['code'] ?: '')) . '</code>
        <code_text>' . xmlEscape(($row['codetext'] ?: '')) . '</code_text>
        <description>' . xmlEscape($row['date'] . ' ' . $row['description']) . '</description>
        <date>' . xmlEscape($row['date']) . '</date>
        <date_formatted>' . xmlEscape(str_replace("-", '', $row['date'])) . '</date_formatted>
        <status>' . xmlEscape('completed') . '</status>
        <age>' . xmlEscape($this->getAge($pid)) . '</age>
        </item>';
            }
        }
        $functional_status .= '</functional_status>';
        $cognitive_status .= '</mental_status>';
        return $functional_status . $cognitive_status;
    }

    public function getClinicalNotes($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " f.encounter = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $clinical_notes = '';
        $query = "SELECT fnote.* FROM forms AS f
                LEFT JOIN `form_clinical_notes` AS fnote ON fnote.`id` = f.`form_id`
                WHERE $wherCon f.`pid` = ? AND f.`formdir` = ? AND f.`deleted` = ? Order By fnote.`encounter`, fnote.`date`, fnote.`clinical_notes_type`";
        array_push($sqlBindArray, $pid, 'clinical_notes', 0);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);

        $clinical_notes .= '<clinical_notes>';
        foreach ($res as $row) {
            if (empty($row['clinical_notes_type'])) {
                continue;
            }
            $tmp = explode(":", $row['code']);
            $code_type = $tmp[0];
            $code = $tmp[1];
            $clt = xmlEscape($row['clinical_notes_type']);
            $clinical_notes .= "<$clt>" .
            '<clinical_notes_type>' . $clt . '</clinical_notes_type>
            <encounter>' . xmlEscape($row['encounter']) . '</encounter>
            <code>' . xmlEscape($code) . '</code>
            <code_text>' . xmlEscape($row['codetext']) . '</code_text>
            <description>' . xmlEscape($row['description']) . '</description>
            <date>' . xmlEscape($row['date']) . '</date>
            <date_formatted>' . xmlEscape(preg_replace('/-/', '', $row['date'])) . '</date_formatted>
            <code_type>' . xmlEscape($code_type) . "</code_type>
            </$clt>";
        }

        $clinical_notes .= '</clinical_notes>';
        return $clinical_notes;
    }

    public function getCareTeamProviderId($pid)
    {
        $appTable = new ApplicationTable();
        $query = "SELECT care_team_provider FROM patient_data WHERE `pid`  = ?";
        $result = $appTable->zQuery($query, array($pid));
        $row = $result->current();
        return $row['care_team_provider'];
    }

    public function getClinicalInstructions($pid, $encounter)
    {
        $wherCon = '';
        $sqlBindArray = [];
        if ($encounter) {
            $wherCon = " f.encounter = ? AND ";
            $sqlBindArray[] = $encounter;
        }

        $query = "SELECT fci.* FROM forms AS f
                LEFT JOIN form_clinical_instructions AS fci ON fci.id = f.form_id
                WHERE $wherCon f.pid = ? AND f.formdir = ? AND f.deleted = ?";
        array_push($sqlBindArray, $pid, 'clinical_instructions', 0);
        $appTable = new ApplicationTable();
        $res = $appTable->zQuery($query, $sqlBindArray);
        $clinical_instructions = '<clinical_instruction>';
        foreach ($res as $row) {
            $clinical_instructions .= '<item>' . xmlEscape($row['instruction']) . '</item>';
        }

        $clinical_instructions .= '</clinical_instruction>';
        return $clinical_instructions;
    }

    public function getReferrals($pid, $encounter)
    {
        // patched out because I can't think of a reason to send a list of referrals
        /*$wherCon = '';
        if ($encounter) {
            $wherCon = "ORDER BY date DESC LIMIT 1";
        }*/
        $wherCon = "ORDER BY date DESC LIMIT 1";

        $appTable = new ApplicationTable();
        $query = "SELECT field_value FROM transactions JOIN lbt_data ON form_id=id AND field_id = 'body' WHERE pid = ? $wherCon";
        $result = $appTable->zQuery($query, array($pid));
        $referrals = '<referral_reason>';
        foreach ($result as $row) {
            $referrals .= '<text>' . xmlEscape($row['field_value']) . '</text>';
        }

        $referrals .= '</referral_reason>';
        return $referrals;
    }

    public function getLatestEncounter($pid)
    {
        $encounter = '';
        $appTable = new ApplicationTable();
        $query = "SELECT encounter FROM form_encounter  WHERE pid = ? ORDER BY id DESC LIMIT 1";
        $result = $appTable->zQuery($query, array($pid));
        foreach ($result as $row) {
            $encounter = $row['encounter'];
        }

        return $encounter;
    }

    public function formatUid($str)
    {
        $sha = sha1($str);
        return substr(preg_replace('/^.{8}|.{4}/', '\0-', $sha, 4), 0, 36);
    }
}

?>
