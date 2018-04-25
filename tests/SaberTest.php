<?php
/**
 * Copyright: Toast Studio
 * Author: Twosee <twose@qq.com>
 * Date: 2018/4/14 下午10:50
 */

namespace Swlib\Tests\Saber;

use PHPUnit\Framework\TestCase;
use Swlib\Http\ContentType;
use Swlib\Http\Exception\ClientException;
use Swlib\Http\Exception\ConnectException;
use Swlib\Http\Exception\HttpExceptionMask;
use Swlib\Http\Exception\ServerException;
use Swlib\Http\Exception\TooManyRedirectsException;
use Swlib\Http\SwUploadFile;
use Swlib\Saber;

class SaberTest extends TestCase
{
    public function testExceptionReport()
    {
        Saber::exceptionReport(HttpExceptionMask::E_NONE);
        $this->assertEquals(HttpExceptionMask::E_NONE, Saber::exceptionReport());
    }

    public function testStaticAndRequests()
    {
        $responses = Saber::requests([
            ['get', 'http://httpbin.org/get'],
            ['delete', 'http://httpbin.org/delete'],
            ['post', 'http://httpbin.org/post', ['foo' => 'bar']],
            ['patch', 'http://httpbin.org/patch', ['foo' => 'bar']],
            ['put', 'http://httpbin.org/put', ['foo' => 'bar']],
        ]);
        $this->assertEquals(0, $responses->error_num);
    }

    public function testInstanceAndRequests()
    {
        $saber = Saber::create(['base_uri' => 'http://httpbin.org']);
        $responses = $saber->requests([
            ['get', '/get'],
            ['delete', '/delete'],
            ['post', '/post', ['foo' => 'bar']],
            ['patch', '/patch', ['foo' => 'bar']],
            ['put', '/put', ['foo' => 'bar']],
        ]);
        $this->assertEquals(0, $responses->error_num);
    }

    public function testDataParser()
    {
        [$json, $xml, $html] = Saber::list([
            'uri' => [
                'http://httpbin.org/get',
                'https://www.javatpoint.com/xmlpages/books.xml',
                'http://httpbin.org/html'
            ]
        ]);
        $this->assertEquals((string)$json->uri, $json->getParsedJsonArray()['url']);
        $this->assertEquals((string)$json->uri, $json->getParsedJsonObject()->url);
        $this->assertEquals('Everyday Italian', $xml->getParsedXmlObject()->book[0]->title);
        $this->assertStringStartsWith(
            'Herman',
            $html->getParsedHtmlObject()->getElementsByTagName('h1')->item(0)->textContent
        );
    }

    public function testSessionAndUriQuery()
    {
        $session = Saber::session([
            'base_uri' => 'http://httpbin.org',
            'redirect' => 0,
            'exception_report' => HttpExceptionMask::E_ALL ^ HttpExceptionMask::E_REDIRECT
        ]);
        $session->get('/cookies/set?apple=orange', [
            'uri_query' => ['apple' => 'banana', 'foo' => 'bar', 'k' => 'v']
        ]);
        $session->get('/cookies/delete?k');
        $cookies = $session->get('/cookies')->getParsedJsonArray()['cookies'];
        $expected = ['apple' => 'banana', 'foo' => 'bar'];
        self::assertEquals($expected, $cookies);
    }

    public function testExceptions()
    {
        $saber = Saber::create(['exception_report' => true]);
        $this->expectException(ConnectException::class);
        $saber->get('http://www.qq.com', ['timeout' => 0.001]);
        $this->expectException(ConnectException::class);
        $saber->get('http://foo.bar');
        $this->expectException(ClientException::class);
        $saber->get('http://httpbin.org/status/401');
        $this->expectException(ServerException::class);
        $saber->get('http://httpbin.org/status/500');
        $this->expectException(TooManyRedirectsException::class);
        $saber->get('http://httpbin.org//redirect/1', ['redirect' => 0]);
    }

    /**
     * @depends testExceptions
     */
    public function testExceptionHandle()
    {
        $saber = Saber::create(['exception_report' => true]);
        $saber->exceptionHandle(function (\Exception $e) use (&$exception) {
            $exception = get_class($e);
            return true;
        });
        $saber->get('http://httpbin.org/status/500');
        $this->assertEquals(ServerException::class, $exception);
    }

    public function testUploadFiles()
    {
        $file1 = __DIR__ . '/black.png';
        $this->assertFileExists($file1);
        $file2 = [
            'path' => __DIR__ . '/black.png',
            'name' => 'white.png',
            'type' => ContentType::get('png'),
            'offset' => null, //re-upload from break
            'size' => null //upload a part of the file
        ];
        $file3 = new SwUploadFile(
            __DIR__ . '/black.png',
            'white.png',
            ContentType::get('png')
        );

        $res = Saber::post('http://httpbin.org/post', null, [
                'files' => [
                    'image1' => $file1,
                    'image2' => $file2,
                    'image3' => $file3
                ]
            ]
        );
        $files = array_keys($res->getParsedJsonArray()['files']);
        $this->assertEquals(['image1', 'image2', 'image3'], $files);
    }

    public function testMark()
    {
        $mark = 'it is request one!';
        $responses = Saber::requests([
            ['uri' => 'http://www.qq.com/', 'mark' => $mark],
            ['uri' => 'http://www.qq.com']
        ]);
        $this->assertEquals($mark, $responses[0]->getSpecialMark());
    }

    public function testInterceptor()
    {
        $target = 'http://www.qq.com/';
        Saber::get($target, [
            'before' => function (Saber\Request $request) use (&$uri) {
                $uri = $request->getUri();
            },
            'after' => function (Saber\Response $response) use (&$success) {
                $success = $response->success;
            }
        ]);
        $this->assertEquals($target, $uri ?? '');
        $this->assertTrue($success ?? false);
    }

    public function testList()
    {
        $uri_list = [
            'http://www.qq.com/',
            'https://www.baidu.com/',
            'http://httpbin.org/'
        ];
        $res = Saber::list(['uri' => $uri_list]);
        $this->assertEquals(count($uri_list), $res->success_num);
    }

}