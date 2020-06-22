<?php

namespace OpenEMR\Tests\Api;

use PHPUnit\Framework\TestCase;
use OpenEMR\Tests\Api\ApiTestClient;
use OpenEMR\Tests\Fixtures\FacilityFixtureManager;

/**
 * Facility API Endpoint Test Cases.
 * @coversDefaultClass OpenEMR\Tests\Api\ApiTestClient
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Yash Bothra <yashrajbothra786gmail.com>
 * @copyright Copyright (c) 2020 Yash Bothra <yashrajbothra786gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 *
 */
class FacilityApiTest extends TestCase
{
    const FACILITY_API_ENDPOINT = "/apis/api/facility";
    private $testClient;
    private $fixtureManager;

    protected function setUp(): void
    {
        $baseUrl = getenv("OPENEMR_BASE_URL", true) ?: "http://localhost";
        $this->testClient = new ApiTestClient($baseUrl, false);
        $this->testClient->setAuthToken(ApiTestClient::OPENEMR_API_AUTH_ENDPOINT);

        $this->fixtureManager = new FacilityFixtureManager();
        $this->facilityRecord = (array) $this->fixtureManager->getSingleFacilityFixture();
    }

    protected function tearDown(): void
    {
        $this->fixtureManager->removeFacilityFixtures();
    }

    /**
     * @covers ::post with an invalid facility request
     */
    public function testInvalidPost()
    {
        unset($this->facilityRecord["name"]);
        $actualResponse = $this->testClient->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * @covers ::post with a valid facility request
     */
    public function testPost()
    {
        $actualResponse = $this->testClient->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);

        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $newFacilityId = $responseBody["data"]["id"];
        $this->assertIsInt($newFacilityId);
        $this->assertGreaterThan(0, $newFacilityId);

        $newFacilityUuid = $responseBody["data"]["uuid"];
        $this->assertIsString($newFacilityUuid);
    }

    /**
     * @covers ::patch with an invalid pid and uuid
     */
    public function testInvalidPatch()
    {
        $actualResponse = $this->testClient->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $this->facilityRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->testClient->patch(
            self::FACILITY_API_ENDPOINT,
            "not-a-uuid",
            $this->facilityRecord
        );

        $this->assertEquals(400, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * @covers ::patch with a valid resource id and payload
     */
    public function testPatch()
    {
        $actualResponse = $this->testClient->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);

        $facilityUuid = $responseBody["data"]["uuid"];

        $this->facilityRecord["email"] = "help@pennfirm.com";
        $actualResponse = $this->testClient->patch(self::FACILITY_API_ENDPOINT, $facilityUuid, $this->facilityRecord);

        $this->assertEquals(200, $actualResponse->getStatusCode());
        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $updatedResource = $responseBody["data"];
        $this->assertEquals($this->facilityRecord["email"], $updatedResource["email"]);
    }

    /**
     * @covers ::getOne with an invalid pid
     */
    public function testGetOneInvalidId()
    {
        $actualResponse = $this->testClient->getOne(self::FACILITY_API_ENDPOINT, "not-a-uuid");
        $this->assertEquals(400, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(1, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals(0, count($responseBody["data"]));
    }

    /**
     * @covers ::getOne with a valid pid
     */
    public function testGetOne()
    {
        $actualResponse = $this->testClient->post(self::FACILITY_API_ENDPOINT, $this->facilityRecord);
        $this->assertEquals(201, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $facilityUuid = $responseBody["data"]["uuid"];
        $facilityId = $responseBody["data"]["id"];

        $actualResponse = $this->testClient->getOne(self::FACILITY_API_ENDPOINT, $facilityUuid);
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));
        $this->assertEquals($facilityUuid, $responseBody["data"]["uuid"]);
        $this->assertEquals($facilityId, $responseBody["data"]["id"]);
    }


    /**
     * @covers ::getAll
     */
    public function testGetAll()
    {
        $this->fixtureManager->installFacilityFixtures();

        $actualResponse = $this->testClient->get(self::FACILITY_API_ENDPOINT, array("facility_npi" => "0123456789"));
        $this->assertEquals(200, $actualResponse->getStatusCode());

        $responseBody = json_decode($actualResponse->getBody(), true);
        $this->assertEquals(0, count($responseBody["validationErrors"]));
        $this->assertEquals(0, count($responseBody["internalErrors"]));

        $searchResults = $responseBody["data"];
        $this->assertGreaterThan(1, $searchResults);

        foreach ($searchResults as $index => $searchResult) {
            $this->assertEquals("0123456789", $searchResult["facility_npi"]);
        }
    }
}
