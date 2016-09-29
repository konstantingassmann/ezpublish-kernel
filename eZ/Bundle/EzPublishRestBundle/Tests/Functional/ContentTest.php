<?php

/**
 * File containing the Functional\ContentTest class.
 *
 * @copyright Copyright (C) eZ Systems AS. All rights reserved.
 * @license For full copyright and license information view LICENSE file distributed with this source code.
 *
 * @version //autogentag//
 */
namespace eZ\Bundle\EzPublishRestBundle\Tests\Functional;

use Buzz\Message\Response;
use eZ\Bundle\EzPublishRestBundle\Tests\Functional\TestCase as RESTFunctionalTestCase;

class ContentTest extends RESTFunctionalTestCase
{
    private $createdContentTypeId = 1;

    /**
     * @covers POST /content/objects
     *
     * @return string REST content ID
     */
    public function testCreateContent()
    {
        $request = $this->createHttpRequest('POST', '/api/ezp/v2/content/objects', 'ContentCreate+xml', 'ContentInfo+json');
        $string = $this->addTestSuffix(__FUNCTION__);
        $body = <<< XML
<?xml version="1.0" encoding="UTF-8"?>
<ContentCreate>
  <ContentType href="/api/ezp/v2/content/types/{$this->createdContentTypeId}" />
  <mainLanguageCode>eng-GB</mainLanguageCode>
  <LocationCreate>
    <ParentLocation href="/api/ezp/v2/content/locations/1/2" />
    <priority>0</priority>
    <hidden>false</hidden>
    <sortField>PATH</sortField>
    <sortOrder>ASC</sortOrder>
  </LocationCreate>
  <Section href="/api/ezp/v2/content/sections/1" />
  <alwaysAvailable>true</alwaysAvailable>
  <remoteId>{$string}</remoteId>
  <User href="/api/ezp/v2/user/users/14" />
  <modificationDate>2012-09-30T12:30:00</modificationDate>
  <fields>
    <field>
      <fieldDefinitionIdentifier>name</fieldDefinitionIdentifier>
      <languageCode>eng-GB</languageCode>
      <fieldValue>{$string}</fieldValue>
    </field>
  </fields>
</ContentCreate>
XML;
        $request->setContent($body);

        $response = $this->sendHttpRequest($request);

        self::assertHttpResponseCodeEquals($response, 201);
        self::assertHttpResponseHasHeader($response, 'Location');

        $href = $response->getHeader('Location');
        $this->addCreatedElement($href);

        return $href;
    }

    /**
     * @depends testCreateContent
     * @covers PUBLISH /content/objects/<contentId>/versions/<versionNumber>
     *
     * @return string REST content ID
     */
    public function testPublishContent($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('PUBLISH', "$restContentHref/versions/1")
        );
        self::assertHttpResponseCodeEquals($response, 204);

        return $restContentHref;
    }

    /**
     * @depends testPublishContent
     * @covers GET /content/objects?remoteId=<remoteId>
     */
    public function testRedirectContent($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', '/api/ezp/v2/content/objects?remoteId=' . $this->addTestSuffix('testCreateContent'))
        );

        self::assertHttpResponseCodeEquals($response, 307);
        self::assertEquals($response->getHeader('Location'), $restContentHref);
    }

    /**
     * @depends testPublishContent
     */
    public function testLoadContent($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', $restContentHref, '', 'ContentInfo+json')
        );

        self::assertHttpResponseCodeEquals($response, 200);

        return $response;
    }

    /**
     * @depends testLoadContent
     */
    public function testLoadContentCacheTags(Response $response)
    {
        $responseStruct = json_decode($response->getContent(), true);

        $this->assertHttpResponseHasCacheTags(
            $response,
            [
                'location-' . $this->extractLastIdFromHref($responseStruct['Content']['MainLocation']['_href']),
                'content-' . $responseStruct['Content']['_id'],
                'content-type-' . $this->extractLastIdFromHref($responseStruct['Content']['ContentType']['_href']),
            ]
        );
    }

    /**
     * @depends testPublishContent
     */
    public function testUpdateContentMetadata($restContentHref)
    {
        $string = $this->addTestSuffix(__FUNCTION__);
        $content = <<< XML
<?xml version="1.0" encoding="UTF-8"?>
<ContentUpdate>
  <Owner href="/api/ezp/v2/user/users/10"/>
  <remoteId>{$string}</remoteId>
</ContentUpdate>
XML;
        $request = $this->createHttpRequest('PATCH', $restContentHref, 'ContentUpdate+xml', 'ContentInfo+json');
        $request->setContent($content);
        $response = $this->sendHttpRequest($request);
        self::assertHttpResponseCodeEquals($response, 200);

        // @todo test data
    }

    /**
     * @depends testPublishContent
     *
     * @return string ContentVersion REST ID
     */
    public function testCreateDraftFromVersion($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('COPY', "{$restContentHref}/versions/1")
        );

        self::assertHttpResponseCodeEquals($response, 201);
        self::assertEquals($response->getHeader('Location'), "{$restContentHref}/versions/2");

        return $response->getHeader('Location');
    }

    /**
     * @depends testPublishContent
     * @covers GET /content/objects/<contentId>/currentversion
     * @covers \eZ\Publish\Core\REST\Server\Controller\Content::redirectCurrentVersion
     */
    public function testRedirectCurrentVersion($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', "$restContentHref/currentversion")
        );

        self::assertHttpResponseCodeEquals($response, 307);
        self::assertHttpResponseHasHeader($response, 'Location', "$restContentHref/versions/1");

        $this->assertHttpResponseHasCacheTags(
            $response,
            [
                'content-' . $this->extractLastIdFromHref($restContentHref),
            ]
        );
    }

    /**
     * @depends testCreateDraftFromVersion
     * @covers GET /content/objects/<contentId>/versions/<versionNumber>
     *
     * @param string $restContentVersionHref
     */
    public function testLoadContentVersion($restContentVersionHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', $restContentVersionHref, '', 'Version+json')
        );

        self::assertHttpResponseCodeEquals($response, 200);
        // @todo test data
        // @todo test filtering (language, fields, etc)

        return $response;
    }

    /**
     * @depends testLoadContentVersion
     */
    public function testLoadContentVersionCacheTags(Response $response)
    {
        $responseStruct = json_decode($response->getContent(), true);

        $this->assertHttpResponseHasCacheTags(
            $response,
            [
                'content-' . $this->extractLastIdFromHref(
                    $responseStruct['Version']['VersionInfo']['Content']['_href']
                ),
                'content-type-' . $this->createdContentTypeId,
            ]
        );
    }

    /**
     * @covers COPY /content/objects/<contentId>
     * @depends testPublishContent
     *
     * @return string the copied content href
     */
    public function testCopyContent($restContentHref)
    {
        $testContent = $this->loadContent($restContentHref);

        $request = $this->createHttpRequest('COPY', $restContentHref);
        $request->addHeader('Destination: ' . $testContent['MainLocation']['_href']);

        $response = $this->sendHttpRequest($request);

        self::assertHttpResponseCodeEquals($response, 201);
        self::assertStringStartsWith('/api/ezp/v2/content/objects/', $response->getHeader('Location'));

        $this->addCreatedElement($response->getHeader('Location'));

        return $response->getHeader('Location');
    }

    /**
     * @covers DELETE /content/objects/<versionNumber>
     * @depends testCopyContent
     */
    public function testDeleteContent($restContentHref)
    {
        self::markTestSkipped("Fails as the content created by copyContent isn't found");
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('DELETE', $restContentHref)
        );

        self::assertHttpResponseCodeEquals($response, 204);
    }

    /**
     * @depends testPublishContent
     * @covers GET /content/objects/<contentId>/versions
     */
    public function testLoadContentVersions($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', "$restContentHref/versions", '', 'VersionList')
        );

        self::assertHttpResponseCodeEquals($response, 200);
    }

    /**
     * @depends testPublishContent
     *
     * @param string $restContentHref /content/objects/<contentId>
     * @covers COPY /content/objects/<contentId>/currentversion
     *
     * @return string the ID of the created version (/content/objects/<contentId>/versions/<versionNumber>
     */
    public function testCreateDraftFromCurrentVersion($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('COPY', "$restContentHref/currentversion")
        );

        self::assertHttpResponseCodeEquals($response, 201);
        self::assertHttpResponseHasHeader($response, 'Location');

        return $response->getHeader('Location');
    }

    /**
     * @depends testCreateDraftFromCurrentVersion
     *
     * @param string $restContentVersionHref /api/ezp/v2/content/objects/<contentId>/versions>/<versionNumber>
     * @covers DELETE /api/ezp/v2/content/objects/<contentId>/versions>/<versionNumber>
     */
    public function testDeleteContentVersion($restContentVersionHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('DELETE', $restContentVersionHref)
        );

        self::assertHttpResponseCodeEquals($response, 204);
    }

    /**
     * @depends testCreateDraftFromVersion
     * @covers PATCH /content/objects/<contentId>/versions>/<versionNumber>
     *
     * @param string $restContentVersionHref /content/objects/<contentId>/versions>/<versionNumber>
     */
    public function testUpdateVersion($restContentVersionHref)
    {
        $xml = <<< XML
<VersionUpdate>
    <fields>
        <field>
            <fieldDefinitionIdentifier>name</fieldDefinitionIdentifier>
            <languageCode>eng-GB</languageCode>
            <fieldValue>testUpdateVersion</fieldValue>
        </field>
    </fields>
</VersionUpdate>
XML;

        $request = $this->createHttpRequest('PATCH', $restContentVersionHref, 'VersionUpdate+xml', 'Version+json');
        $request->setContent($xml);
        $response = $this->sendHttpRequest(
            $request
        );

        self::assertHttpResponseCodeEquals($response, 200);
    }

    /**
     * @depends testPublishContent
     * @covers GET /content/objects/<contentId>/relations
     */
    public function testRedirectCurrentVersionRelations($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', "$restContentHref/relations")
        );

        self::assertHttpResponseCodeEquals($response, 307);

        // @todo Fix, see EZP-21059. Meanwhile, the test is skipped if it fails as expected
        // self::assertHttpResponseHasHeader( $response, 'Location', "$restContentHref/versions/1/relations" );
        self::assertHttpResponseHasHeader($response, 'Location', "$restContentHref/relations?versionNumber=1");
        self::markTestIncomplete('@todo Fix issue EZP-21059');
    }

    /**
     * @depends testCreateDraftFromVersion
     * @covers GET /content/objects/<contentId>/versions/<versionNumber>/relations
     */
    public function testLoadVersionRelations($restContentVersionHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', "$restContentVersionHref/relations")
        );

        self::assertHttpResponseCodeEquals($response, 200);

        $this->assertHttpResponseHasCacheTags(
            $response,
            [
                'content-' . $this->extractContentIdFromHref($restContentVersionHref),
            ]
        );
    }

    /**
     * @depends testCreateDraftFromVersion
     * @covers POST /content/objects/<contentId>/versions/<versionNumber>/relations/<relationId>
     *
     * @return string created relation HREF (/content/objects/<contentId>/versions/<versionNumber>/relations/<relationId>
     */
    public function testCreateRelation($restContentVersionHref)
    {
        $content = <<< XML
<?xml version="1.0" encoding="UTF-8"?>
<RelationCreate>
  <Destination href="/api/ezp/v2/content/objects/10"/>
</RelationCreate>
XML;

        $request = $this->createHttpRequest('POST', "$restContentVersionHref/relations", 'RelationCreate+xml', 'Relation+json');
        $request->setContent($content);

        $response = $this->sendHttpRequest($request);

        self::assertHttpResponseCodeEquals($response, 201);

        $response = json_decode($response->getContent(), true);

        return $response['Relation']['_href'];
    }

    /**
     * @depends testCreateRelation
     * @covers GET /content/objects/<contentId>/versions/<versionNo>/relations/<relationId>
     */
    public function testLoadVersionRelation($restContentRelationHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', $restContentRelationHref)
        );

        self::assertHttpResponseCodeEquals($response, 200);

        $this->assertHttpResponseHasCacheTags(
            $response,
            ['content-' . $this->extractContentIdFromHref($restContentRelationHref)]
        );
    }

    /**
     * Returns the Content key from the decoded JSON of $restContentId's contentInfo.
     *
     *
     * @throws \InvalidArgumentException
     *
     * @param string $restContentHref /api/ezp/v2/content/objects/<contentId>
     *
     * @return array
     */
    private function loadContent($restContentHref)
    {
        $response = $this->sendHttpRequest(
            $this->createHttpRequest('GET', $restContentHref, '', 'ContentInfo+json')
        );

        if ($response->getStatusCode() != 200) {
            throw new \InvalidArgumentException("Content with ID $restContentHref could not be loaded");
        }

        $array = json_decode($response->getContent(), true);
        if ($array === null) {
            self::fail('Error loading content. Response: ' . $response->getContent());
        }

        return $array['Content'];
    }

    public function testCreateView()
    {
        $body = <<< XML
<?xml version="1.0" encoding="UTF-8"?>
<ViewInput>
  <identifier>testCreateView</identifier>
  <Query>
    <Criteria>
      <ContentTypeIdentifierCriterion>folder</ContentTypeIdentifierCriterion>
    </Criteria>
    <limit>10</limit>
    <offset>0</offset>
  </Query>
</ViewInput>
XML;
        $request = $this->createHttpRequest('POST', '/api/ezp/v2/content/views', 'ViewInput+xml', 'View+json');
        $request->setContent($body);
        $response = $this->sendHttpRequest(
            $request
        );

        // Returns 301 since 6.0 (deprecated in favour of /views)
        self::assertHttpResponseCodeEquals($response, 301);
        self::assertHttpResponseHasHeader($response, 'Location');
    }

    /**
     * Asserts that $response has the given set of $cacheTags.
     *
     * @param Response $response
     * @param array $expectedTags Example: ['content-42', 'location-300']
     */
    private function assertHttpResponseHasCacheTags(Response $response, $expectedTags)
    {
        $this->assertHttpResponseHasHeader($response, 'xkey');

        $responseCacheTag = $response->getHeader('xkey');
        foreach ($expectedTags as $expectedTag) {
            $this->assertContains($expectedTag, $responseCacheTag);
        }
    }

    /**
     * Extracts and returns the last id from $href.
     *
     * @param string $href Ex: '/api/ezp/v2/content/objects/1'
     * @return int Ex: 1
     */
    protected function extractLastIdFromHref($href)
    {
        $contentTypeHrefParts = explode('/', $href);

        return (int)array_pop($contentTypeHrefParts);
    }

    private function extractPathFromHref($href)
    {
        $parts = array_filter(
            explode('/', str_replace('/api/ezp/v2/', '', $href)),
            function ($value) {
                return is_numeric($value);
            }
        );

        return $parts;
    }

    /**
     * Extracts a content id from any href containing one.
     *
     * @param string $href Ex: /api/ezp/v2/content/objects/1/anything
     * @return int
     */
    private function extractContentIdFromHref($href)
    {
        $contentId = null;
        $leftOvers = null;

        sscanf(
            $href,
            '/api/ezp/v2/content/objects/%d/%s',
            $contentId,
            $leftOvers
        );

        return $contentId;
    }
}
