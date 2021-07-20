<?php

/**
 * RestControllerHelper
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Services\FHIR\IResourceSearchableService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRPatient;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCanonical;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCode;
use OpenEMR\FHIR\R4\FHIRElement\FHIRExtension;
use OpenEMR\FHIR\R4\FHIRElement\FHIRRestfulCapabilityMode;
use OpenEMR\FHIR\R4\FHIRElement\FHIRTypeRestfulInteraction;
use OpenEMR\FHIR\R4\FHIRResource;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCapabilityStatement\FHIRCapabilityStatementInteraction;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCapabilityStatement\FHIRCapabilityStatementOperation;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCapabilityStatement\FHIRCapabilityStatementResource;
use OpenEMR\FHIR\R4\FHIRResource\FHIRCapabilityStatement\FHIRCapabilityStatementRest;
use OpenEMR\Services\FHIR\IResourceUSCIGProfileService;
use OpenEMR\Validators\ProcessingResult;

class RestControllerHelper
{
    /**
     * The resource endpoint names we want to skip over.
     */
    const IGNORE_ENDPOINT_RESOURCES = ['.well-known', 'metadata'];

    /**
     * The default FHIR services class namespace
     */
    const FHIR_SERVICES_NAMESPACE = "OpenEMR\\Services\\FHIR\\Fhir";

    // @see https://www.hl7.org/fhir/search.html#table
    const FHIR_SEARCH_CONTROL_PARAM_REV_INCLUDE_PROVENANCE = "Provenance:target";

    /**
     * Configures the HTTP status code and payload returned within a response.
     *
     * @param $serviceResult
     * @param $customRespPayload
     * @param $idealStatusCode
     * @return null
     */
    public static function responseHandler($serviceResult, $customRespPayload, $idealStatusCode)
    {
        if ($serviceResult) {
            http_response_code($idealStatusCode);

            if ($customRespPayload) {
                return $customRespPayload;
            }
            return $serviceResult;
        }

        // if no result is present return a 404 with a null response
        http_response_code(404);
        return null;
    }

    public static function validationHandler($validationResult)
    {
        if (property_exists($validationResult, 'isValid') && !$validationResult->isValid()) {
            http_response_code(400);
            $validationMessages = null;
            if (property_exists($validationResult, 'getValidationMessages')) {
                $validationMessages = $validationResult->getValidationMessages();
            } else {
                $validationMessages = $validationResult->getMessages();
            }
            return $validationMessages;
        }
        return null;
    }

    /**
     * Parses a service processing result for standard Apis to determine the appropriate HTTP status code and response format
     * for a request.
     *
     * The response body has a uniform structure with the following top level keys:
     * - validationErrors
     * - internalErrors
     * - data
     *
     * The response data key conveys the data payload for a response. The payload is either a "top level" array for a
     * single result, or an array for multiple results.
     *
     * @param        $processingResult         - The service processing result.
     * @param        $successStatusCode        - The HTTP status code to return for a successful operation that completes without error.
     * @param        $isMultipleResultResponse - Indicates if the response contains multiple results.
     * @return array[]
     */
    public static function handleProcessingResult($processingResult, $successStatusCode, $isMultipleResultResponse = false): array
    {
        $httpResponseBody = [
            "validationErrors" => [],
            "internalErrors" => [],
            "data" => []
        ];
        if (!$processingResult->isValid()) {
            http_response_code(400);
            $httpResponseBody["validationErrors"] = $processingResult->getValidationMessages();
        } elseif ($processingResult->hasInternalErrors()) {
            http_response_code(500);
            $httpResponseBody["internalErrors"] = $processingResult->getInternalErrors();
        } else {
            http_response_code($successStatusCode);
            $dataResult = $processingResult->getData();

            if (!$isMultipleResultResponse) {
                $dataResult = (count($dataResult) === 0) ? [] : $dataResult[0];
            }

            $httpResponseBody["data"] = $dataResult;
        }

        return $httpResponseBody;
    }

    /**
     * Parses a service processing result for FHIR endpoints to determine the appropriate HTTP status code and response format
     * for a request.
     *
     * The response body has a normal Fhir Resource json:
     *
     * @param        $processingResult  - The service processing result.
     * @param        $successStatusCode - The HTTP status code to return for a successful operation that completes without error.
     * @return array|mixed
     */
    public static function handleFhirProcessingResult(ProcessingResult $processingResult, $successStatusCode)
    {
        $httpResponseBody = [];
        if (!$processingResult->isValid()) {
            http_response_code(400);
            $httpResponseBody["validationErrors"] = $processingResult->getValidationMessages();
        } elseif (count($processingResult->getData()) <= 0) {
            http_response_code(404);
        } elseif ($processingResult->hasInternalErrors()) {
            http_response_code(500);
            $httpResponseBody["internalErrors"] = $processingResult->getInternalErrors();
        } else {
            http_response_code($successStatusCode);
            $dataResult = $processingResult->getData();

            $httpResponseBody = $dataResult[0];
        }

        return $httpResponseBody;
    }

    public function setSearchParams($resource, FHIRCapabilityStatementResource $capResource, $service)
    {
        if (empty($service)) {
            return; // nothing to do here as the service isn't defined.
        }

        if (!$service instanceof IResourceSearchableService) {
            return; // nothing to do here as the source is not searchable.
        }

        if (empty($capResource->getSearchInclude())) {
            $capResource->addSearchInclude('*');
        }
        if ($service instanceof IResourceUSCIGProfileService && empty($capResource->getSearchRevInclude())) {
            $capResource->addSearchRevInclude(self::FHIR_SEARCH_CONTROL_PARAM_REV_INCLUDE_PROVENANCE);
        }
        $searchParams = $service->getSearchParams();
        $searchParams = is_array($searchParams) ? $searchParams : [];
        foreach ($searchParams as $fhirSearchField => $searchDefinition) {

            /**
             * @var FhirSearchParameterDefinition $searchDefinition
             */

            $paramExists = false;
            $type = $searchDefinition->getType();

            foreach ($capResource->getSearchParam() as $searchParam) {
                if (strcmp($searchParam->getName(), $fhirSearchField) == 0) {
                    $paramExists = true;
                }
            }
            if (!$paramExists) {
                $param = new FHIRResource\FHIRCapabilityStatement\FHIRCapabilityStatementSearchParam();
                $param->setName($fhirSearchField);
                $param->setType($type);
                $capResource->addSearchParam($param);
            }
        }
    }


    /**
     * Retrieves the fully qualified service class name for a given FHIR resource.  It will only return a class that
     * actually exists.
     * @param $resource The name of the FHIR resource that we attempt to find the service class for.
     * @param string $serviceClassNameSpace  The namespace to find the class in.  Defaults to self::FHIR_SERVICES_NAMESPACE
     * @return string|null  Returns the fully qualified name if the class is found, otherwise it returns null.
     */
    public function getFullyQualifiedServiceClassForResource($resource, $serviceClassNameSpace = self::FHIR_SERVICES_NAMESPACE)
    {
        $serviceClass = $serviceClassNameSpace . $resource . "Service";
        if (class_exists($serviceClass)) {
            return $serviceClass;
        }
        return null;
    }

    public function addOperations($resource, $items, FHIRCapabilityStatementResource $capResource)
    {
        $operation = end($items);
        // we want to skip over anything that's not a resource $operation
        if ($operation === '$export') {
            $operationName = strtolower($resource) . '-export';
            // define export operation
            $resource = new FHIRPatient();
            $operation = new FHIRCapabilityStatementOperation();
            $operation->setName($operationName);
            $operation->setDefinition(new FHIRCanonical('http://hl7.org/fhir/uv/bulkdata/OperationDefinition/' . $operationName));

            // TODO: adunsulag so the Single Patient API fails on this expectation being here yet the Multi-Patient API failed when it wasn't here
            // need to investigate what, if anything we are missing, perhaps another extension definition that tells the inferno server
            // that this should be parsed in a single patient context??
//            $extension = new FHIRExtension();
//            $extension->setValueCode(new FHIRCode('SHOULD'));
//            $extension->setUrl('http://hl7.org/fhir/StructureDefinition/capabilitystatement-expectation');
//            $operation->addExtension($extension);
//            $capResource->addOperation($operation);
        }
    }

    public function addRequestMethods($items, FHIRCapabilityStatementResource $capResource)
    {
        $reqMethod = trim($items[0], " ");
        $numberItems = count($items);
        $code = "";
        // we want to skip over $export operations.
        if (end($items) === '$export') {
            return;
        }

        // now setup our interaction types
        if (strcmp($reqMethod, "GET") == 0) {
            if (!empty(preg_match('/:/', $items[$numberItems - 1]))) {
                $code = "read";
            } else {
                $code = "search-type";
            }
        } elseif (strcmp($reqMethod, "POST") == 0) {
            $code = "insert";
        } elseif (strcmp($reqMethod, "PUT") == 0) {
            $code = "update";
        }

        if (!empty($code)) {
            $interaction = new FHIRCapabilityStatementInteraction();
            $restfulInteraction = new FHIRTypeRestfulInteraction();
            $restfulInteraction->setValue($code);
            $interaction->setCode($restfulInteraction);
            $capResource->addInteraction($interaction);
        }
    }


    public function getCapabilityRESTObject($routes, $serviceClassNameSpace = self::FHIR_SERVICES_NAMESPACE, $structureDefinition = "http://hl7.org/fhir/StructureDefinition/"): FHIRCapabilityStatementRest
    {
        $restItem = new FHIRCapabilityStatementRest();
        $mode = new FHIRRestfulCapabilityMode();
        $mode->setValue('server');
        $restItem->setMode($mode);

        $resourcesHash = array();
        foreach ($routes as $key => $function) {
            $items = explode("/", $key);
            if ($serviceClassNameSpace == self::FHIR_SERVICES_NAMESPACE) {
                // FHIR routes always have the resource at $items[2]
                $resource = $items[2];
            } else {
                // API routes do not always have the resource at $items[2]
                if (count($items) < 5) {
                    $resource = $items[2];
                } elseif (count($items) < 7) {
                    $resource = $items[4];
                    if (substr($resource, 0, 1) === ':') {
                        // special behavior needed for the API portal route
                        $resource = $items[3];
                    }
                } else { // count($items) < 9
                    $resource = $items[6];
                }
            }

            if (!in_array($resource, self::IGNORE_ENDPOINT_RESOURCES)) {
                $service = null;
                $serviceClass = $this->getFullyQualifiedServiceClassForResource($resource, $serviceClassNameSpace);
                if (!empty($serviceClass)) {
                    $service = new $serviceClass();
                }
                if (!array_key_exists($resource, $resourcesHash)) {
                    $capResource = new FHIRCapabilityStatementResource();
                    $capResource->setType(new FHIRCode($resource));
                    $capResource->setProfile(new FHIRCanonical($structureDefinition . $resource));

                    if ($service instanceof IResourceUSCIGProfileService) {
                        $profileUris = $service->getProfileURIs();
                        foreach ($profileUris as $uri) {
                            $capResource->addSupportedProfile(new FHIRCanonical($uri));
                        }
                    }
                    $resourcesHash[$resource] = $capResource;
                }
                $this->setSearchParams($resource, $resourcesHash[$resource], $service);
                $this->addRequestMethods($items, $resourcesHash[$resource]);
                $this->addOperations($resource, $items, $resourcesHash[$resource]);
            }
        }

        foreach ($resourcesHash as $resource => $capResource) {
            $restItem->addResource($capResource);
        }
        return $restItem;
    }
}
