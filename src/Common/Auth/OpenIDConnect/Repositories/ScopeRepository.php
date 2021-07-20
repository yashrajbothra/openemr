<?php

/**
 * Authorization Server Member
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Common\Auth\OpenIDConnect\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\ClientEntity;
use OpenEMR\Common\Auth\OpenIDConnect\Entities\ScopeEntity;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Common\System\System;
use OpenEMR\RestControllers\RestControllerHelper;
use Psr\Log\LoggerInterface;

class ScopeRepository implements ScopeRepositoryInterface
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    private $validationScopes;

    /**
     * @var
     */
    private $requestScopes;

    /**
     * Session string containing the scopes populated in the current session.
     * @var string
     */
    private $sessionScopes;

    /**
     * @var \RestConfig
     */
    private $restConfig;

    /**
     * Array(string => callback) Where the string is the route and the callback is the route handler
     * @var array
     */
    private $fhirRouteMap = [];

    /**
     * Array(string => callback) Where the string is the route and the callback is the route handler
     * @var array
     */
    private $routeMap = [];

    /**
     * Array(string => callback) Where the string is the route and the callback is the route handler
     * @var array
     */
    private $portalRouteMap = [];

    /**
     * ScopeRepository constructor.
     * @param $restConfig \RestConfig normally we would typesafe this, but RestConfig isn't in the autoloader so we leave it out so we can unit test this class better
     */
    public function __construct($restConfig = null)
    {
        $this->logger = new SystemLogger();
        $this->requestScopes = isset($_REQUEST['scope']) ? $_REQUEST['scope'] : null;
        $this->sessionScopes = $_SESSION['scopes'] ?? '';
        $this->restConfig = $restConfig;
        if (!empty($restConfig)) {
            $this->fhirRouteMap = $restConfig::$FHIR_ROUTE_MAP ?? [];
            $this->routeMap = $restConfig::$ROUTE_MAP ?? [];
            $this->portalRouteMap = $restConfig::$PORTAL_ROUTE_MAP ?? [];
        }
    }

    /**
     * @return array
     */
    public function getFhirRouteMap(): array
    {
        return $this->fhirRouteMap;
    }

    /**
     * @param array $fhirRouteMap
     */
    public function setFhirRouteMap(array $fhirRouteMap): void
    {
        $this->fhirRouteMap = $fhirRouteMap;
    }

    /**
     * @return array
     */
    public function getStandardRouteMap(): array
    {
        return $this->routeMap;
    }

    /**
     * @param array $routeMap
     */
    public function setStandardRouteMap(array $routeMap): void
    {
        $this->routeMap = $routeMap;
    }

    /**
     * @return array
     */
    public function getPortalRouteMap(): array
    {
        return $this->portalRouteMap;
    }

    /**
     * @param array $portalRouteMap
     */
    public function setPortalRouteMap(array $portalRouteMap): void
    {
        $this->portalRouteMap = $portalRouteMap;
    }

    /**
     * @param string $identifier
     * @return ScopeEntity|null
     */
    public function getScopeEntityByIdentifier($identifier): ?ScopeEntity
    {
        $this->logger->debug("ScopeRepository->getScopeEntityByIdentifier() attempting to retrieve scope", ["identifier" => $identifier]);
        if (empty($this->validationScopes)) {
            $this->logger->debug("ScopeRepository->getScopeEntityByIdentifier() attempting to build validation scopes");
            $this->validationScopes = $this->buildScopeValidatorArray();
        }

        if (array_key_exists($identifier, $this->validationScopes) === false && stripos($identifier, 'site:') === false) {
            $this->logger->error("ScopeRepository->getScopeEntityByIdentifier() request access to invalid scope", [
                "scope" => $identifier
                , 'validationScopes' => $this->validationScopes]);
            return null;
        }

        $scope = new ScopeEntity();
        $scope->setIdentifier($identifier);

        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null
    ): array {
        $finalizedScopes = [];
        // we only let scopes that the client initially registered with through instead of whatever they request in
        // their grant.
        if ($clientEntity instanceof ClientEntity) {
            $clientScopes = $clientEntity->getScopes();
            foreach ($scopes as $scope) {
                if (\in_array($scope->getIdentifier(), $clientScopes)) {
                    $finalizedScopes[] = $scope;
                }
            }
        } else {
            $this->logger->error("client entity was not an instance of ClientEntity and scopes could not be retrieved");
        }

        // If a nonce is passed in, add a nonce scope for id token nonce claim
        if (!empty($_SESSION['nonce'])) {
            $scope = new ScopeEntity();
            $scope->setIdentifier('nonce');
            $finalizedScopes[] = $scope;
        }

        // Need a site id for our apis
        $finalizedScopes[] = $this->getSiteScope();
        return $finalizedScopes;
    }

    public function getSiteScope(): ScopeEntity
    {
        // TODO: adunsulag, sjpadget Won't refresh token validation fail on multi-site since we won't
        // have the id in the session?
        if ($_SESSION['site_id']) {
            $siteScope = "site:" . $_SESSION['site_id'];
        } else {
            $siteScope = "site:default";
        }
        $scope = new ScopeEntity();
        $scope->setIdentifier($siteScope);
        return $scope;
    }

    public function setRequestScopes($scopes)
    {
        if (!is_string($scopes)) {
            (new SystemLogger())->error("Attempted to set request scopes to something other than a string", ['scopes' => $scopes]);
            throw new \InvalidArgumentException("Invalid scope parameter set");
        }

        $this->requestScopes = $scopes;
    }

    public function getRequestScopes()
    {
        return $this->requestScopes;
    }

    public function getSessionScopes()
    {
        return $this->sessionScopes;
    }

    // nonce claim and nonce scope is handled by server logic.
    // current locale is enUS need to set from openemr locale.
    public function getSupportedClaims(): array
    {
        return array(
            "profile",
            "email",
            "email_verified",
            "phone",
            "phone_verified",
            "family_name",
            "given_name",
            "fhirUser",
            "locale",
            "api:oemr",
            "api:fhir",
            "api:port",
            "aud", //client_id
            "iat", // token create time
            "iss", // token issuer(https://domain)
            "exp", // token expiry time.
            "sub" // the subject of token. usually patient UUID.
        );
    }

    public function oidcScopes(): array
    {
        return [
            "openid",
            "profile",
            "name",
            "address",
            "given_name",
            "family_name",
            "nickname",
            "phone",
            "phone_verified",
            "email",
            "email_verified",
            "offline_access",
            "api:oemr",
            "api:fhir",
            "api:port"
        ];
    }

    public function fhirRequiredSmartScopes(): array
    {
        $requiredSmart = [
            "openid",
            "fhirUser",
            "online_access",
            "offline_access",
            "launch",
            "launch/patient",
            "api:oemr",
            "api:fhir",
            "api:port",
        ];
        // we define our Bulk FHIR here
        // There really is no defined standard on how to handle SMART scopes for operations ($operation)
        // hopefully its defined in V2, but for now we are going to implement using the following scopes
        // @see https://chat.fhir.org/#narrow/stream/179170-smart/topic/SMART.20scopes.20and.20custom.20operations/near/156832330
        if (isset($this->restConfig) && $this->restConfig->areSystemScopesEnabled()) {
            $requiredSmart[] = 'system/Patient.$export';
            $requiredSmart[] = 'system/Group.$export';
            $requiredSmart[] = 'system/*.$bulkdata-status';
            $requiredSmart[] = 'system/*.$export';
        }
        return $requiredSmart;
    }

    /**
     * @Method fhirScopes
     *
     * Returns all fhir permitted scopes and permissions.
     * These will be qualified against existing and future routes
     * with role(patient, user or system facing applications).
     *
     * @return array
     */
    public function fhirScopes(): array
    {
        // Note: we only allow patient/read access for FHIR apps right now, if someone wants write access they have to
        // have a user/<resource>.write permission as the security context for patient only rights has too much risk
        // for problems.

        // we've restricted our patient scopes just to what we have in portal.  We will slowly open them up as we
        // verify the access rights on them.
        $permitted = [
//            "patient/Account.read",
            "patient/AllergyIntolerance.read",
//            "patient/AllergyIntolerance.write",
//            "patient/Appointment.read",
//            "patient/Appointment.write",
            "patient/CarePlan.read",
            "patient/CareTeam.read",
            "patient/Condition.read",
//            "patient/Condition.write",
//            "patient/Consent.read",
//            "patient/Coverage.read",
//            "patient/Coverage.write",
            "patient/DiagnosticReport.read",
            "patient/Device.read",
            "patient/DocumentReference.read",
//            "patient/DocumentReference.write",
            "patient/Encounter.read",
//            "patient/Encounter.write",
            "patient/Goal.read",
            "patient/Immunization.read",
//            "patient/Immunization.write",
            "patient/Location.read",
            "patient/Medication.read",
            "patient/MedicationRequest.read",
//            "patient/MedicationRequest.write",
//            "patient/NutritionOrder.read",
            "patient/Observation.read",
//            "patient/Observation.write",
            "patient/Organization.read",
//            "patient/Organization.write",
            "patient/Patient.read",
//            "patient/Patient.write",
            "patient/Person.read",
            "patient/Practitioner.read",
//            "patient/Practitioner.write",
//            "patient/PractitionerRole.read",
//            "patient/PractitionerRole.write",
            "patient/Procedure.read",
//            "patient/Procedure.write",
            "patient/Provenance.read",
//            "patient/Provenance.write",
//            "patient/RelatedPerson.read",
//            "patient/RelatedPerson.write",
//            "patient/Schedule.read",
//            "patient/ServiceRequest.read",
            "user/Account.read",
            "user/AllergyIntolerance.read",
            "user/AllergyIntolerance.write",
            "user/Appointment.read",
            "user/Appointment.write",
            "user/CarePlan.read",
            "user/CareTeam.read",
            "user/Condition.read",
            "user/Condition.write",
            "user/Consent.read",
            "user/Coverage.read",
            "user/Coverage.write",
            "user/Device.read",
            "user/DiagnosticReport.read",
            "user/DocumentReference.read",
            "user/DocumentReference.write",
            "user/Encounter.read",
            "user/Encounter.write",
            "user/Goal.read",
            "user/Immunization.read",
            "user/Immunization.write",
            "user/Location.read",
            "user/Medication.read",
            "user/MedicationRequest.read",
            "user/MedicationRequest.write",
            "user/NutritionOrder.read",
            "user/Observation.read",
            "user/Observation.write",
            "user/Organization.read",
            "user/Organization.write",
            "user/Patient.read",
            "user/Patient.write",
            "user/Person.read",
            "user/Practitioner.read",
            "user/Practitioner.write",
            "user/PractitionerRole.read",
            "user/PractitionerRole.write",
            "user/Procedure.read",
            "user/Procedure.write",
            "user/Provenance.read",
            "user/Provenance.write",
            "user/RelatedPerson.read",
            "user/RelatedPerson.write",
            "user/Schedule.read",
            "user/ServiceRequest.read"
        ];

        if ($this->restConfig->areSystemScopesEnabled()) {
            return array_merge($permitted, $this->systemScopes());
        }
        return $permitted;
    }

    public function systemScopes(): array
    {
        return [
            "system/Account.read",
            "system/AllergyIntolerance.read",
            "system/AllergyIntolerance.write",
            "system/Appointment.read",
            "system/Appointment.write",
            "system/CarePlan.read",
            "system/CareTeam.read",
            "system/Condition.read",
            "system/Condition.write",
            "system/Consent.read",
            "system/Coverage.read",
            "system/Coverage.write",
            "system/Document.read", // used for Bulk FHIR export downloads
            "system/DocumentReference.read",
            "system/DocumentReference.write",
            "system/Encounter.read",
            "system/Encounter.write",
            "system/Goal.read",
            "system/Group.read",
            "system/Immunization.read",
            "system/Immunization.write",
            "system/Location.read",
            "system/Medication.read",
            "system/MedicationRequest.read",
            "system/MedicationRequest.write",
            "system/NutritionOrder.read",
            "system/Observation.read",
            "system/Observation.write",
            "system/Organization.read",
            "system/Organization.write",
            "system/Patient.read",
            "system/Patient.write",
            "system/Person.read",
            "system/Practitioner.read",
            "system/Practitioner.write",
            "system/PractitionerRole.read",
            "system/PractitionerRole.write",
            "system/Procedure.read",
            "system/Procedure.write",
            "system/Provenance.read",
            "system/Provenance.write",
            "system/RelatedPerson.read",
            "system/RelatedPerson.write",
            "system/Schedule.read",
            "system/ServiceRequest.read",
        ];
    }

    public function apiScopes(): array
    {
        return [
            "patient/allergy.read",
            "patient/allergy.write",
            "patient/appointment.read",
            "patient/appointment.write",
            "patient/dental_issue.read",
            "patient/dental_issue.write",
            "patient/document.read",
            "patient/document.write",
            "patient/drug.read",
            "patient/encounter.read",
            "patient/encounter.write",
            "patient/facility.read",
            "patient/facility.write",
            "patient/immunization.read",
            "patient/insurance.read",
            "patient/insurance.write",
            "patient/insurance_company.read",
            "patient/insurance_company.write",
            "patient/insurance_type.read",
            "patient/list.read",
            "patient/medical_problem.read",
            "patient/medical_problem.write",
            "patient/medication.read",
            "patient/medication.write",
            "patient/message.read",
            "patient/message.write",
            "patient/patient.read",
            "patient/patient.write",
            "patient/practitioner.read",
            "patient/practitioner.write",
            "patient/prescription.read",
            "patient/procedure.read",
            "patient/soap_note.read",
            "patient/soap_note.write",
            "patient/surgery.read",
            "patient/surgery.write",
            "patient/vital.read",
            "patient/vital.write",
            "system/allergy.read",
            "system/allergy.write",
            "system/appointment.read",
            "system/appointment.write",
            "system/dental_issue.read",
            "system/dental_issue.write",
            "system/document.read",
            "system/document.write",
            "system/drug.read",
            "system/encounter.read",
            "system/encounter.write",
            "system/facility.read",
            "system/facility.write",
            "system/immunization.read",
            "system/insurance.read",
            "system/insurance.write",
            "system/insurance_company.read",
            "system/insurance_company.write",
            "system/insurance_type.read",
            "system/list.read",
            "system/medical_problem.read",
            "system/medical_problem.write",
            "system/medication.read",
            "system/medication.write",
            "system/message.read",
            "system/message.write",
            "system/patient.read",
            "system/patient.write",
            "system/practitioner.read",
            "system/practitioner.write",
            "system/prescription.read",
            "system/procedure.read",
            "system/soap_note.read",
            "system/soap_note.write",
            "system/surgery.read",
            "system/surgery.write",
            "system/vital.read",
            "system/vital.write",
            "user/allergy.read",
            "user/allergy.write",
            "user/appointment.read",
            "user/appointment.write",
            "user/dental_issue.read",
            "user/dental_issue.write",
            "user/document.read",
            "user/document.write",
            "user/drug.read",
            "user/encounter.read",
            "user/encounter.write",
            "user/facility.read",
            "user/facility.write",
            "user/immunization.read",
            "user/insurance.read",
            "user/insurance.write",
            "user/insurance_company.read",
            "user/insurance_company.write",
            "user/insurance_type.read",
            "user/list.read",
            "user/medical_problem.read",
            "user/medical_problem.write",
            "user/medication.read",
            "user/medication.write",
            "user/message.read",
            "user/message.write",
            "user/patient.read",
            "user/patient.write",
            "user/practitioner.read",
            "user/practitioner.write",
            "user/prescription.read",
            "user/procedure.read",
            "user/soap_note.read",
            "user/soap_note.write",
            "user/surgery.read",
            "user/surgery.write",
            "user/vital.read",
            "user/vital.write",
        ];
    }

    public function getOidcSupportedScopes(): array
    {
        return $this->oidcScopes();
    }

    public function getSystemFhirSupportedScopes(): array
    {
        return $this->systemScopes();
    }

    public function getStandardApiSupportedScopes(): array
    {
        return $this->apiScopes();
    }

    public function getServerScopes(): array
    {
        $siteScope = $this->getSiteScope();
        return [
            $siteScope->getIdentifier() => $siteScope->getIdentifier()
        ];
    }

    /**
     * Method will qualify current scopes based on active FHIR resources.
     * Allowed permissions are validated from the default scopes and client role.
     *
     * @param string $role
     * @return array
     */
    public function getCurrentSmartScopes(): array
    {
        (new SystemLogger())->debug("ScopeRepository->getCurrentSmartScopes() setting up smart scopes");
        $gbl = $this->restConfig;
        $restHelper = new RestControllerHelper();
        // Collect all currently enabled FHIR resources.
        // Then assign all permissions the resource is capable.
        $scopes_api = [];
        $restAPIs = $restHelper->getCapabilityRESTObject($this->getFhirRouteMap());
        foreach ($restAPIs->getResource() as $resource) {
            $resourceType = $resource->getType()->getValue();
            $interactions = $resource->getInteraction();
            foreach ($interactions as $interaction) {
                $scopeRead =  $resourceType . ".read";
                $scopeWrite = $resourceType . ".write";
                $interactionCode = $interaction->getCode()->getValue();
                switch ($interactionCode) {
                    case 'read':
                        $scopes_api['patient/' . $scopeRead] = 'patient/' . $scopeRead;
                        $scopes_api['user/' . $scopeRead] = 'user/' . $scopeRead;
                        $scopes_api['system/' . $scopeRead] = 'system/' . $scopeRead;
                        break;
                    case 'search-type':
                        $scopes_api['patient/' . $scopeRead] = 'patient/' . $scopeRead;
                        $scopes_api['user/' . $scopeRead] = 'user/' . $scopeRead;
                        $scopes_api['system/' . $scopeRead] = 'system/' . $scopeRead;
                        break;
                    case 'insert':
                    case 'update':
                        $scopes_api['patient/' . $scopeWrite] = 'patient/' . $scopeWrite;
                        $scopes_api['user/' . $scopeWrite] = 'user/' . $scopeWrite;
                        $scopes_api['system/' . $scopeWrite] = 'system/' . $scopeWrite;
                        break;
                }
            }

            // if we needed to define scopes based on operations rather than the predefined Bulk-FHIR operations
            // we would handle them here.  Leaving this commented out just for reference
            // @var array
            // $operations = $resource->getOperation();
        }

        $scopesSupported = $this->fhirScopes();
        $scopes_dict = array_combine($scopesSupported, $scopesSupported);
        $fhir = array_combine($this->fhirRequiredSmartScopes(), $this->fhirRequiredSmartScopes());
        $oidc = array_combine($this->oidcScopes(), $this->oidcScopes());
        // we need to make sure the 'site:' and any other server context vars are permitted
        $serverScopes = $this->getServerScopes();
        $scopesSupported = null;
        // verify scope permissions are allowed for role being used.
        foreach ($scopes_api as $key => $scope) {
            if (empty($scopes_dict[$key])) {
                continue;
            }
            $scopesSupported[$key] = $scope;
        }
        asort($scopesSupported);
        $scopesSupported = array_keys(array_merge($fhir, $oidc, $serverScopes, $scopesSupported));
        (new SystemLogger())->debug("ScopeRepository->getCurrentSmartScopes() scopes supported ", ["scopes" => $scopesSupported]);

        return $scopesSupported;
    }

    public function getCurrentStandardScopes(): array
    {
        (new SystemLogger())->debug("ScopeRepository->getCurrentStandardScopes() setting up standard api scopes");
        $restHelper = new RestControllerHelper();
        // Collect all currently enabled resources.
        // Then assign all permissions the resource is capable.
        $scopes_api = [];
        $restAPIs = $restHelper->getCapabilityRESTObject($this->getStandardRouteMap(), "OpenEMR\\Services");
        foreach ($restAPIs->getResource() as $resource) {
            $resourceType = $resource->getType()->getValue();
            $interactions = $resource->getInteraction();
            foreach ($interactions as $interaction) {
                $scopeRead =  $resourceType . ".read";
                $scopeWrite = $resourceType . ".write";
                $interactionCode = $interaction->getCode()->getValue();
                switch ($interactionCode) {
                    case 'read':
                        $scopes_api['user/' . $scopeRead] = 'user/' . $scopeRead;
                        $scopes_api['system/' . $scopeRead] = 'system/' . $scopeRead;
                        break;
                    case 'search-type':
                        $scopes_api['user/' . $scopeRead] = 'user/' . $scopeRead;
                        $scopes_api['system/' . $scopeRead] = 'system/' . $scopeRead;
                        break;
                    case 'put':
                    case 'insert':
                    case 'update':
                        $scopes_api['user/' . $scopeWrite] = 'user/' . $scopeWrite;
                        $scopes_api['system/' . $scopeWrite] = 'system/' . $scopeWrite;
                        break;
                }
            }
        }
        $scopes_api_portal = [];
        // TODO: should we put this into the constructor? makes it hard to unit test this...
        if (!empty($GLOBALS['rest_portal_api'])) {
            $restAPIs = $restHelper->getCapabilityRESTObject($this->getPortalRouteMap(), "OpenEMR\\Services");
            foreach ($restAPIs->getResource() as $resource) {
                $resourceType = $resource->getType()->getValue();
                $interactions = $resource->getInteraction();
                foreach ($interactions as $interaction) {
                    $scopeRead =  $resourceType . ".read";
                    $scopeWrite = $resourceType . ".write";
                    $interactionCode = $interaction->getCode()->getValue();
                    switch ($interactionCode) {
                        case 'read':
                            $scopes_api_portal['patient/' . $scopeRead] = 'patient/' . $scopeRead;
                            break;
                        case 'search-type':
                            $scopes_api_portal['patient/' . $scopeRead] = 'patient/' . $scopeRead;
                            break;
                        case 'put':
                        case 'insert':
                        case 'update':
                            $scopes_api_portal['patient/' . $scopeWrite] = 'patient/' . $scopeWrite;
                            break;
                    }
                }
            }
        }
        $scopes_api = array_merge($scopes_api, $scopes_api_portal);

        $scopesSupported = $this->apiScopes();
        $scopes_dict = array_combine($scopesSupported, $scopesSupported);
        $scopesSupported = null;
        // verify scope permissions are allowed for role being used.
        foreach ($scopes_api as $key => $scope) {
            if (empty($scopes_dict[$key])) {
                continue;
            }
            $scopesSupported[$key] = $scope;
        }
        asort($scopesSupported);

        return array_keys($scopesSupported);
    }

    /**
     * Returns true if the session or request has a fhir api scope in it
     * @return bool
     */
    public function hasFhirApiScopes()
    {
        $requestScopeString = $this->getRequestScopes();
        $sessionScopeString = $this->getSessionScopes();

        $isFhir = preg_match('(fhirUser|api:fhir)', $requestScopeString)
            || preg_match('(fhirUser|api:fhir)', $sessionScopeString);
        return $isFhir !== false;
    }

    /**
     * Returns true if the session or request has a standard or portal api scope in it
     * @return bool
     */
    public function hasStandardApiScopes()
    {
        $requestScopeString = $this->getRequestScopes();
        $sessionScopeString = $this->getSessionScopes();
        $isApi = preg_match('(api:oemr|api:port)', $requestScopeString)
            || preg_match('(api:oemr|api:port)', $sessionScopeString);

        return $isApi !== false;
    }

    // made public for now!
    public function buildScopeValidatorArray(): array
    {
        $requestScopeString = $this->getRequestScopes();
        $isFhir = $this->hasFhirApiScopes();
        $isApi = $this->hasStandardApiScopes();
        (new SystemLogger())->debug(
            "ScopeRepository->buildScopeValidatorArray() ",
            ["requestScopeString" => $requestScopeString, 'isStandardApi' => $isApi, 'isFhirApi' => $isFhir]
        );

        // TODO: adunsulag check with @bradymiller and @sjpadgett on defaulting api to $isFhir not all SMART apps request
        // fhirUser and if we want to support the larger ecosystem of apps we need to not require api:fhir or fhirUser

        $scopesFhir = [];
        if ($isFhir || !$isApi) {
            $scopesFhir = $this->getCurrentSmartScopes();
        }
        $scopesApi = [];
        if ($isApi) {
            $scopesApi = $this->getCurrentStandardScopes();
        }
        $mergedScopes = array_merge($scopesFhir, $scopesApi);
        $scopes = [];

        foreach ($mergedScopes as $scope) {
            $scopes[$scope] = ['description' => 'OpenId Connect'];
        }

        return $scopes;
    }
}
