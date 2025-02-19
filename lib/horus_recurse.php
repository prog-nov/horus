<?php

use OpenTracing\Formats;
use OpenTracing\GlobalTracer;
use Jaeger\Config;

class HorusRecurse
{
    public $common = null;
    public $http = null;
    public $business = null;
    public $xml = null;
    public $business_id = '';
    public $tracer = null;

    function __construct($business_id, $log_location, $tracer)
    {
        $this->common = new HorusCommon($business_id, $log_location, 'INDIGO');
        $this->http = new HorusHttp($business_id, $log_location, 'INDIGO', $tracer);
        $this->business = new HorusBusiness($business_id, $log_location, 'INDIGO', $tracer);
        $this->xml = new HorusXml($business_id, $log_location, 'INDIGO', $tracer);
        $this->business_id = $business_id;
        $this->tracer = $tracer;
    }

    function getPart($order, $matches)
    {
        foreach ($matches['parts'] as $part) {
            if ($order == $part['order']) {
                return $part;
            }
        }
        return array();
    }

    function flattenHeaders($headersArray)
    {
        $result = array();
        foreach ($headersArray as $elt) {
            foreach ($elt as $key => $value) {
                if (strpos($key, 'x-horus-') === 0) {
                    $zkey = substr($value, 8);
                    if (strpos($value, ';') >= 0)
                        $mkey = explode(';', $value, 2);
                    else
                        $mkey = array($zkey, $value);
                    $result[] = array('key' => $mkey[0], 'value' => $mkey[1]);
                }
            }
        }
        return $result;
    }

    function findSection($name, $matches)
    {
        foreach ($matches as $section) {
            if ($section['section'] === $name) {
                return $section;
            }
        }
        return null;
    }

    function doRecurse($reqBody, $content_type, $proxy_mode, $matches, $accept, $params, $span)
    {
        $currentSpan = $span;
        //$this->tracer->startSpan('Begin Recurse',['child_of'=>$span]);

        if (!array_key_exists('section', $params)) {
            throw new HorusException('Section URL parameter is unknown');
        }

        $section = $this->findSection($params['section'], $matches);

        //$span->log(['message'=>$params['section']]);

        if ($content_type !== $section['content-type']) {
            throw new HorusException('Section ' . $params['section'] . " was supposed to be of type " . $section['content-type'] . ' but found ' . $content_type . ' instead');
        }
        $result = null;
        if ('application/xml' === $content_type) {
            $result = $this->doRecurseXml($reqBody, $section, $params, $currentSpan);
            //Jaeger\Config::getInstance()->flush();
        } elseif ('application/json' === $content_type) {
            $result = $this->doRecurseJson($reqBody, $section, $params, $currentSpan);
        } else {
            throw new HorusException('Unsupported content-type ' . $content_type);
        }

        $urlparams = array_merge($params, $this->flattenHeaders($result['headers']));

        $returnHeaders = array();
        if ('' !== $proxy_mode) {
            $destination = HorusHttp::formatQueryString($proxy_mode, $urlparams, array('section'));
        } else {
            $destination = '';
            $returnHeaders = $urlparams;
        }
        //$this->tracer->reportSpan();
        try {
            $ret = $this->http->returnWithContentType($result['xml'], $content_type, 200, $destination, false, 'POST', $returnHeaders, $currentSpan);
        } catch (Exception $e) {
            //error_log($e->getMessage());
        }

        return $ret;
    }


    function doRecurseXml($body, $section, $queryParams, $span)
    {
        $elements = array();
        $xmlBody = simplexml_load_string($body);
        if (array_key_exists('namespaces', $section)) {
            $this->xml->registerExtraNamespaces($xmlBody, $section['namespaces']);
        }

        if (array_key_exists('validator', $section)) {
            foreach ($section['validator'] as $validator) {
                try {
                    $span->log(['message' => 'Validating signature ' . $validator['name']]);

                    HorusXML::validateSignature($body, $queryParams, $validator, $this->common->cnf);
                    $this->common->mlog('Validated ' . $validator['name'] . ' Signature', 'INFO');
                } catch (HorusException $e) {
                    $this->common->mlog($validator['name'] . ' Signature failed : ' . $e->getMessage(), 'ERROR');
                    //throw new HorusException($e);
                }
            }
        }

        $headers = array();

        foreach ($section['parts'] as $part) {
            $currentSpan = $this->tracer->startSpan('Part ' . $part['order'] . ' ' . $part['comment'], ['child_of' => $span]);
            $this->common->mlog('Dealing with part #' . $part['order'] . ' : ' . $part['comment'], 'INFO');
            $inputXmlPart = null;
            $vars = $queryParams;
            if (array_key_exists('variables', $part)) {
                $this->common->mlog('Extracting variables for part #' . $part['order'], 'DEBUG');
                $currentSpan->log(['message' => 'Get variables']);
                foreach ($part['variables'] as $name => $xpath) {
                    $elt = array('key' => $name, 'value' => $this->xml->getXpathVariable($xmlBody, $xpath));
                    $this->common->mlog('  Variable ' . $elt['key'] . ' = ' . $elt['value'], 'DEBUG');
                    $vars[] = $elt;
                }
            }
            if (array_key_exists('path', $part)) {
                $currentSpan->log(['message' => 'Get document part']);
                $this->common->mlog('Extracting document from XPath=' . $part['path'], 'DEBUG');
                $inputXmlPart = $xmlBody->xpath($part['path']);
                if (FALSE !== $inputXmlPart && is_array($inputXmlPart) && (count($inputXmlPart) > 0)) {
                    $xpathResult = $inputXmlPart[0];

                    $ddom = dom_import_simplexml($xpathResult);

                    $newdom = new DomDocument('1.0', 'utf-8');
                    $newdom->appendChild($newdom->importNode($ddom, true));

                    $correctedxmlpart =  $newdom->saveXml($newdom->documentElement);
                    $this->common->mlog('Part Contents : ' . $correctedxmlpart, 'DEBUG');
                    $finalUrl = $this->common->formatQueryString($part['transformUrl'], $vars, TRUE);
                    $this->common->mlog('Transformation URL is : ' . $finalUrl, 'DEBUG');
                    $currentSpan->log(['message' => 'Forward to ' . $finalUrl]);
                    $resp = $this->http->forwardSingleHttpQuery($finalUrl, array('Content-type: application/xml', 'Accept: application/xml', 'Expect: ', 'X-Business-Id: ' . $this->business_id), $correctedxmlpart, 'POST', $currentSpan);
                    $currentSpan->log(['message' => 'Got response']);
                    $headers[$part['order']] = $resp['headers'];
                    $rr = simplexml_load_string($resp['body']);
                    $this->common->mlog('Part Transformed : ' . $rr->saveXML(), 'DEBUG');
                    $elements[$part['order']] = $rr;
                } else {
                    $currentSpan->finish();
                    throw new HorusException('Could not extract location ' . $part['path'] . ' for part #' . $part['order']);
                }
            } else {
                if (array_key_exists('constant', $part)) {
                    $nsp = $part['constant']['namespace'];
                    $tag = $part['constant']['elementName'];

                    $value = self::getVar($part['constant']['variableName'], $vars);

                    $rr = simplexml_load_string('<' . $tag . ' xmlns="' . $nsp . '">' . $value . '</' . $tag . '>');
                    $elements[$part['order']] = $rr;
                } else {
                    $currentSpan->finish();
                    throw new HorusException('No XPath to search for in configuration');
                }
            }
            $currentSpan->finish();
        }

        //$nextSpan = $this->tracer->startSpan('Build Document',['child_of'=>$span]);
        //$nextSpan->log(['message'=>'Build initial document']);
        $dom = new DomDocument();

        $rootns = '';
        if (preg_match('/\:/', $section['rootElement'])) {
            $split = explode(':', $section['rootElement'])[0];
            if (preg_match('/^\//', $split)) {
                $split = substr($split, 1);
            }
            foreach ($section['namespaces'] as $ns) {
                if (array_key_exists('namespace', $ns)) {
                    if ($split === $ns['prefix']) {
                        $rootns = $ns['namespace'];
                        break;
                    }
                } elseif (array_key_exists('element', $ns)) {
                    if ($split === $ns['prefix']) {
                        $rootns = $this->xml->searchNameSpace($ns['element'], $xmlBody);
                        break;
                    }
                }
            }
        }

        if (preg_match('/^\//', $section['rootElement'])) {
            $elementName = substr($section['rootElement'], 1);
        } else {
            $elementName =  $section['rootElement'];
        }

        $this->common->mlog("Root NS = " . $rootns . ' / Root Elt = ' . $elementName, 'DEBUG');
        if ($rootns === '') {
            $root = new DomElement($elementName);
        } else {
            $root = new DomElement($elementName, null, $rootns);
        }
        $dom->appendChild($root);

        //$nextSpan->log(['message'=>'Build document']);

        foreach ($elements as $index => $element) {
            $part = $this->getPart($index, $section);
            if (array_key_exists('targetPath', $part)) {
                $this->common->mlog("Added element to XML response " . $index . ' at ' . $part['targetPath'], 'INFO');
                $node = $this->addPath($root, $part['targetPath'], $section['namespaces']);
            } else {
                $this->common->mlog("Added element to XML response " . $index, 'INFO');
                $node = $root;
            }
            $this->common->mlog("Parent Element is " . $node->localName . ' (' . $node->namespaceURI . ')', 'DEBUG');

            $domElement = $dom->importNode(dom_import_simplexml($element), TRUE);
            $node->appendChild($domElement);
        }

        //$nextSpan->finish();

        return array('xml' => $dom->saveXml(), 'headers' => $headers);
    }

    function doRecurseJson($reqBody, $matches, $queryParams)
    {
        $this->common->mlog("Called Recurse Json with parameters " . print_r($matches, true) . ' and document = ' . print_r($reqBody, true) . ' and queryParameters = ' . $queryParams, 'INFO');
    }

    function addPath($root, $targetPath, $namespaces)
    {
        $tree = explode('/', $targetPath);
        array_shift($tree);   // Remove First element (coming from the heading slash in XPath)
        array_shift($tree);   // Remove Root Element
        array_pop($tree);     // Remove last Element (the one to add)
        $this->common->mlog('Xml Tree : ' . print_r($tree, true), 'DEBUG');
        $node = $root;
        $this->common->mlog("Root is " . $root->prefix . ':' . $root->localName, 'DEBUG');

        foreach ($tree as $leaf) {
            $this->common->mlog("Testing output XML : is element " . $leaf . ' present?', 'DEBUG');
            $ns = explode(':', $leaf);
            if (count($ns) === 1) {
                $prefix = '';
                $path = $ns[0];
            } else {
                $prefix = $ns[0];
                $path = $ns[1];
            }
            $uri = self::getNSUriFromPrefix($prefix, $namespaces);
            $this->common->mlog("Testing output XML : testing " . $path . ' (' . $uri . ')', 'DEBUG');
            $cc = $root->getElementsByTagNameNS($uri, $path);
            $this->common->mlog(' Elements count = ' . $cc->length, 'DEBUG');

            if ($cc->length !== 1) {
                $this->common->mlog("Testing output XML : element " . $path . ' not found. Creating it under node ' . $node->prefix . ':' . $node->localName, 'DEBUG');
                $elt = new DomElement($path, '', $uri);
                $node->appendChild($elt);
            }
            $node = $root->getElementsByTagNameNS($uri, $path)->item(0);
        }

        return $node;
    }

    static function getNSUriFromPrefix($prefix, $namespaces)
    {

        if (is_null($prefix) || !is_array($namespaces) || (count($namespaces) == 0) || is_null($prefix) || ($prefix == '')) {
            return '';
        }

        foreach ($namespaces as $namespace) {
            if ($namespace['prefix'] === $prefix) {
                return $namespace['namespace'];
            }
        }
        return '';
    }

    static function getVar($name, $vars)
    {
        foreach ($vars as $var) {
            if ($var['key'] == $name) {
                return $var['value'];
            }
        }
        return '';
    }
}
