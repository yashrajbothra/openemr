<?php

/**
 * FacilityRestController
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Matthew Vita <matthewvita48@gmail.com>
 * @copyright Copyright (c) 2018 Matthew Vita <matthewvita48@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\RestControllers;

use OpenEMR\Services\FacilityService;
use OpenEMR\RestControllers\RestControllerHelper;

class FacilityRestController
{
    private $facilityService;

    /**
     * White list of facility search fields
     */
    private const WHITELISTED_FIELDS = array(
        "name",
        "phone",
        "fax",
        "street",
        "city",
        "state",
        "postal_code",
        "country_code",
        "federal_ein",
        "website",
        "email",
        "domain_identifier",
        "facility_npi",
        "facility_taxonomy",
        "facility_code",
        "billing_location",
        "accepts_assignment",
        "oid",
        "service_location"
    );

    public function __construct()
    {
        $this->facilityService = new FacilityService();
    }

    public function getOne($uuid)
    {
        $processingResult = $this->facilityService->getOne($uuid);

        if (!$processingResult->hasErrors() && count($processingResult->getData()) == 0) {
            return RestControllerHelper::handleProcessingResult($processingResult, 404);
        }

        return RestControllerHelper::handleProcessingResult($processingResult, 200);
    }

    public function getAll($search = array())
    {
        $validSearchFields = $this->facilityService->filterData($search, self::WHITELISTED_FIELDS);
        $processingResult = $this->facilityService->getAll($validSearchFields);
        return RestControllerHelper::handleProcessingResult($processingResult, 200, true);
    }

    public function post($data)
    {
        $filteredData = $this->facilityService->filterData($data, self::WHITELISTED_FIELDS);
        $processingResult = $this->facilityService->insert($filteredData);
        return RestControllerHelper::handleProcessingResult($processingResult, 201);
    }

    public function patch($uuid, $data)
    {
        $filteredData = $this->facilityService->filterData($data, self::WHITELISTED_FIELDS);
        $processingResult = $this->facilityService->update($uuid, $filteredData);
        return RestControllerHelper::handleProcessingResult($processingResult, 200);
    }
}
