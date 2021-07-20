<?php

/**
 * FhirPatientDocumentReferenceService.php
 * @package openemr
 * @link      http://www.open-emr.org
 * @author    Stephen Nielson <stephen@nielson.org>
 * @copyright Copyright (c) 2021 Stephen Nielson <stephen@nielson.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\DocumentReference;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRDocumentReference;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAttachment;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRIdentifier;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRPeriod;
use OpenEMR\FHIR\R4\FHIRElement\FHIRString;
use OpenEMR\FHIR\R4\FHIRElement\FHIRUrl;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDocumentReference\FHIRDocumentReferenceContent;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDocumentReference\FHIRDocumentReferenceContext;
use OpenEMR\Services\DocumentService;
use OpenEMR\Services\FHIR\FhirCodeSystemConstants;
use OpenEMR\Services\FHIR\FhirOrganizationService;
use OpenEMR\Services\FHIR\FhirProvenanceService;
use OpenEMR\Services\FHIR\FhirServiceBase;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\FHIR\UtilsService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirPatientDocumentReferenceService extends FhirServiceBase
{
    use FhirServiceBaseEmptyTrait;
    use PatientSearchTrait;

    /**
     * @var DocumentService
     */
    private $service;

    public function __construct($fhirApiURL = null)
    {
        parent::__construct($fhirApiURL);
        $this->service = new DocumentService();
    }


    public function supportsCategory($category)
    {
        // we have no category definitions right now for patient
        return false;
    }


    public function supportsCode($code)
    {
        // we don't support searching by code
        return false;
    }

    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATETIME, ['date']),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('uuid', ServiceField::TYPE_UUID)]),
        ];
    }

    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        if (isset($openEMRSearchParameters['category'])) {
            // we have nothing with this category so we are going to return nothing as we never should have gotten here

            unset($openEMRSearchParameters['category']);
        }
        return $this->service->search($openEMRSearchParameters);
    }

    public function parseOpenEMRRecord($dataRecord = array(), $encode = false)
    {
        $docReference = new FHIRDocumentReference();
        $meta = new FHIRMeta();
        $meta->setVersionId('1');
        $meta->setLastUpdated(gmdate('c'));
        $docReference->setMeta($meta);

        $id = new FHIRId();
        $id->setValue($dataRecord['uuid']);
        $docReference->setId($id);

        $identifier = new FHIRIdentifier();
        $identifier->setValue(new FHIRString($dataRecord['uuid']));
        $docReference->addIdentifier($identifier);

        // TODO: @adunsulag need to support content.attachment.url

        if (!empty($dataRecord['date'])) {
            $docReference->setDate(gmdate('c', strtotime($dataRecord['date'])));
        } else {
            $docReference->setDate(UtilsService::createDataMissingExtension());
        }

        if (!empty($dataRecord['euuid'])) {
            $context = new FHIRDocumentReferenceContext();

            // we currently don't track anything dealing with start and end date for the context
            if (!empty($dataRecord['encounter_date'])) {
                $period = new FHIRPeriod();
                $period->setStart(gmdate('c', strtotime($dataRecord['encounter_date'])));
                $context->setPeriod($period);
            }
            $context->addEncounter(UtilsService::createRelativeReference('Encounter', $dataRecord['euuid']));
            $docReference->setContext($context);
        }

        // populate our clinical narrative notes
        if (!empty($dataRecord['id'])) {
            $url = $this->getFhirApiURL() . '/fhir/Document/' . $dataRecord['id'] . '/Binary';
            $content = new FHIRDocumentReferenceContent();
            $attachment = new FHIRAttachment();
            $attachment->setContentType($dataRecord['mimetype']);
            $attachment->setUrl(new FHIRUrl($url));
            $content->setAttachment($attachment);
            // TODO: if we support tagging a specific document with a reference code we can put that here.
            // since it's plain text we have no other interpretation so we just use the mime type sufficient IHE Format code
            $contentCoding = UtilsService::createCoding(
                "urn:ihe:iti:xds:2017:mimeTypeSufficient",
                "mimeType Sufficient",
                FhirCodeSystemConstants::IHE_FORMATCODE_CODESYSTEM
            );
            $content->setFormat($contentCoding);
            $docReference->addContent($content);
        } else {
            // need to support data missing if its not there.
            $docReference->addContent(UtilsService::createDataMissingExtension());
        }

        if (!empty($dataRecord['puuid'])) {
            $docReference->setSubject(UtilsService::createRelativeReference('Patient', $dataRecord['puuid']));
        } else {
            $docReference->setSubject(UtilsService::createDataMissingExtension());
        }

        // the category is extensible so we are going to use our own code set, this is just a uniquely identifying URL
        // which is extensible so we are going to put this here
        // if we have a way of translating LOINC to category we could do this, but for now we don't
        // TODO: @adunsulag check with @brady.miller is there anyway to translate our document categories into LOINC codes?

        $docCategoryValueSetSystem = $this->getFhirApiURL() . "/fhir/ValueSet/openemr-document-types";
        $docReference->addCategory(UtilsService::createCodeableConcept(['openemr-document' => 'OpenEMR Document'], $docCategoryValueSetSystem));

        $fhirOrganizationService = new FhirOrganizationService();
        $orgReference = $fhirOrganizationService->getPrimaryBusinessEntityReference();
        $docReference->setCustodian($orgReference);

        // TODO: @adunsulag check with @sjpadgett and @brady.miller how are patient documents setup?  Do they populate the owner column?
        // how do you determine if a patient uploaded a document vs a regular user?
        if (!empty($dataRecord['user_uuid'])) {
            if (!empty($dataRecord['npi'])) {
                $docReference->addAuthor(UtilsService::createRelativeReference('Practitioner', $dataRecord['user_uuid']));
            } else {
                // if we don't have a practitioner reference then it is the business owner that will be the author on
                // the clinical notes
                $docReference->addAuthor($orgReference);
            }
        }

        if (!empty($dataRecord['deleted'])) {
            if ($dataRecord['deleted'] != 1) {
                $docReference->setStatus("current");
            } else {
                $docReference->setStatus("entered-in-error");
            }
        } else {
            $docReference->setStatus('current');
        }

        if (!empty($dataRecord['code'])) {
            $type = UtilsService::createCodeableConcept($dataRecord['code'], FhirCodeSystemConstants::LOINC, $dataRecord['codetext']);
            $docReference->setType($type);
        } else {
            $docReference->setType(UtilsService::createNullFlavorUnknownCodeableConcept());
        }

        return $docReference;
    }

    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRDocumentReference)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }

        $fhirProvenanceService = new FhirProvenanceService();
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord);
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }
}
