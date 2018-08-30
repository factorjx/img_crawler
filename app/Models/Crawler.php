<?php

namespace App\Models;

use Goutte\Client as GoutteClient;
use Symfony\Component\BrowserKit\Client as ScrapClient;
use GuzzleHttp\Exception\ClientException;
use Symfony\Component\DomCrawler\Crawler as DomCrawler;

class Crawler
{

    /**
     * scrap client used for crawling the files, can be either Goutte or local file system
     * @var ScrapClient $scrapClient
     */
    protected $scrapClient;

    /**
     * The base URL from which the crawler begins crawling
     * @var string
     */
    protected $baseUrl;

    /**
     * The max depth the crawler will crawl
     * @var int
     */
    protected $maxDepth;

    /**
     * The max depth the crawler will crawl
     * @var int
     */
    protected $maxPages;

    protected $currentCrawlPages;

    /**
     * Array of links (and related data) found by the crawler
     * @var array
     */
    protected $links;

    /**
     * whether file to be crawled is local file or remote one
     * @var boolean
     */
    protected $localFile;

    /**
     * callable for filtering specific links and prevent crawling others
     * @var \Closure
     */
    protected $filterCallback;

    /**
     * set logger to the crawler
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * store options to guzzle client associated with crawler
     * @var array
     */
    protected $configOptions;

    /**
     * store children links arranged by depth to apply breadth first search
     * @var array
     */
    private $childrenByDepth;


    public function __construct($baseUrl, $maxDepth = 3, $maxPages = 0)
    {
        $this->baseUrl = $baseUrl;
        $this->maxDepth = $maxDepth;
        $this->maxPages = 50;
        $this->currentCrawlPages = 0;
        $this->links = [];
    }

    public function traverse($url = null)
    {
        if ($url === null) {
            $url = $this->removeDotsFromPath($this->baseUrl);
        }

        $this->links[$url] = [
            'links_text' => ['BASE_URL'],
            'absolute_url' => $url,
            'frequency' => 1,
            'visited' => false,
            'external_link' => false,
            'original_urls' => [$url],
            'source_link'   => "",
            'img_count' => 0,
        ];
        $this->traverseSingle($url, 0);

        for($depth=1; $depth<$this->maxDepth; $depth++){
            if(!isset($this->childrenByDepth[$depth])){
                continue;
            }

            foreach($this->childrenByDepth[$depth] as $parentLink => $urls){
                foreach($urls as $url){
                        if($this->currentCrawlPages >= $this->maxPages){
                            break 3;
                        }
                        $this->traverseSingle($url, $depth, $parentLink);
                }
            }

        }
        dd($this->links, $this->currentCrawlPages);
        return $this;
    }

    /**
     * Get links (and related data) found by the crawler
     * @return array
     */
    public function getLinks()
    {
        if ($this->filterCallback === null) {
            $links = $this->links;
        } else {
            $links = array_filter($this->links, function ($link_info) {
                if (isset($link_info['dont_visit'])===true &&
                        $link_info['dont_visit']===true) {
                    return false;
                } else {
                    return true;
                }
            });
        }

        return $links;
    }

    /**
     * Crawl single URL
     * @param string $url
     * @param int    $depth
     */
    protected function traverseSingle($url, $depth, $parentUrl = '')
    {

        $hash = $this->getPathFromUrl($url);

        if (isset($this->links[$hash]['dont_visit']) &&
                $this->links[$hash]['dont_visit']===true) {
            return;
        }

        // $filterLinks = $this->filterCallback;
        // if ($filterLinks !== null && $filterLinks($url) === false) {
        //         $this->links[$hash]['dont_visit'] = true;
        //         $this->log(LogLevel::INFO, 'skipping "'.$url.'" url not matching filter criteria', ['depth'=>$depth]);
        //         return;
        // }


        try {
            $client = $this->getScrapClient();
            $crawler = $client->request('GET', $this->getAbsoluteUrl($url), [],[],[],null,false); //disable change history
            /*@var $response \Symfony\Component\BrowserKit\Response */
            $response = $client->getResponse();
            $statusCode = $response->getStatus();
            if(empty($parentUrl)){
                $parentUrl = $this->baseUrl;
            }

            if ($url == $parentUrl) {
                $hash = $url;
            } else {
                $hash = $this->getPathFromUrl($url, $parentUrl);
            }
            $this->links[$hash]['status_code'] = $statusCode;
            if(!isset($this->links[$hash]['depth'])){ //if already exist in previous depth, don't override
                $this->links[$hash]['depth'] = $depth;
            }

            if ($statusCode === 200) {
                $content_type = $response->getHeader('Content-Type');

                //traverse children in case the response in HTML document only
                if (strpos($content_type, 'text/html') !== false) {

                    $this->countImages($crawler, $hash);

                    $childLinks = array();
                    if (isset($this->links[$hash]['external_link']) === true && $this->links[$hash]['external_link'] === false) {
                        $childLinks = $this->extractLinksInfo($crawler, $hash);
                    }
                    $this->links[$hash]['visited'] = true;
                    $this->currentCrawlPages++;
                    $this->traverseChildren($hash, $childLinks, $depth+1);
                }
            }else{
                $this->links[$hash]['error_message'] = $statusCode;
            }
        } catch (ClientException $e) {
            $this->links[$url]['status_code'] = $e->getResponse()->getStatusCode();
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage().' in line '.$e->getLine();

        } catch (\Exception $e) {
            $this->links[$url]['status_code'] = '404';
            $this->links[$url]['error_code'] = $e->getCode();
            $this->links[$url]['error_message'] = $e->getMessage();
        }
    }

    /**
     * create and configure goutte client used for scraping
     * @return GoutteClient
     */
    public function getScrapClient()
    {
        if (!$this->scrapClient) {
            //default client will be Goutte php Scrapper
            $client = new GoutteClient();
            $client->followRedirects();
            $configOptions = $this->configureGuzzleOptions();
            $guzzleClient = new \GuzzleHttp\Client($configOptions);
            $client->setClient($guzzleClient);
            $this->scrapClient = $client;
        }

        return $this->scrapClient;
    }

    public function setScrapClient($client)
    {
        $this->scrapClient = $client;
    }

    /**
     * set callback to filter links by specific criteria
     * @param \Closure $filterCallback
     * @return \Arachnid\Crawler
     */
    public function filterLinks(\Closure $filterCallback)
    {
        $this->filterCallback = $filterCallback;
        return $this;
    }

    /**
     * Crawl child links
     * @param string $sourceUrl
     * @param array $childLinks
     * @param int   $depth
     */
    public function traverseChildren($sourceUrl, $childLinks, $depth)
    {
        foreach ($childLinks as $url => $info) {

            $filterCallback = $this->filterCallback;
            $hash = $this->getPathFromUrl($url, $sourceUrl);

            if ( isset($this->links[$hash]['dont_visit']) &&
                    $this->links[$hash]['dont_visit']===true) {
                return;
            }

            if (isset($this->links[$hash]) === false) {
                $this->links[$hash] = $info;
            		$this->links[$hash]['source_link'] = $this->getAbsoluteUrl($sourceUrl);
            		$this->links[$hash]['depth'] = $depth;
            } else {
                $this->links[$hash]['original_urls'] = isset($this->links[$hash]['original_urls'])
                        ? array_merge($this->links[$hash]['original_urls'], $info['original_urls'])
                        : $info['original_urls'];
                $this->links[$hash]['links_text'] = isset($this->links[$hash]['links_text'])
                        ? array_merge($this->links[$hash]['links_text'], $info['links_text'])
                        : $info['links_text'];
                }

            if (isset($this->links[$hash]['visited']) === false) {
                $this->links[$hash]['visited'] = false;
            }

            $this->childrenByDepth[$depth][$sourceUrl][] = $hash;
        }



    }

    /**
     * Extract links information from url
     * @param  \Symfony\Component\DomCrawler\Crawler $crawler
     * @param  string                                $url
     * @return array
     */
    public function extractLinksInfo(DomCrawler $crawler, $url)
    {
        $childLinks = [];
        $crawler->filter('a')->each(function (DomCrawler $node, $i) use (&$childLinks, $url) {
            $nodeText = trim($node->text());
            $nodeUrl = $node->attr('href');
            $nodeUrlIsCrawlable = $this->checkIfCrawlable($nodeUrl);


            $normalizedLink = $this->normalizeLink($nodeUrl);
            $hash = $this->getPathFromUrl($normalizedLink, $url);

            if (isset($this->links[$hash]) === false) {
                $childLinks[$hash]['original_urls'][$nodeUrl] = $nodeUrl;

                if ($nodeUrlIsCrawlable === true) {
                    // Ensure URL is formatted as absolute
                    $childLinks[$hash]['absolute_url'] = $this->getAbsoluteUrl($nodeUrl, $url);

                    // Is this an external URL?
                    $childLinks[$hash]['external_link'] = $this->checkIfExternal($childLinks[$hash]['absolute_url']);

                    //frequency or visited
                    if (isset($childLinks[$hash]['visited']) === false) {
                        $childLinks[$hash]['visited'] = false;
                    }
                    $childLinks[$hash]['frequency'] = isset($childLinks[$hash]['frequency']) ?
                            $childLinks[$hash]['frequency'] + 1 : 1;
                } else {
                    $childLinks[$hash]['visited'] = false;
                    $childLinks[$hash]['dont_visit'] = true;
                    $childLinks[$hash]['external_link'] = false;
                }
            }
        });


        return $childLinks;
    }

    protected function countImages(DomCrawler $crawler, $url)
    {
        $img_count = $crawler->filter('img')->count();
        $this->links[$url]['img_count'] = $img_count;
    }

    /**
     * Is a given URL crawlable?
     * @param  string $uri
     * @return bool
     */
    protected function checkIfCrawlable($uri)
    {
        if (empty($uri) === true) {
            return false;
        }

        $stop_links = [
            '@^javascript\:.*$@i',
            '@^#.*@',
            '@^mailto\:.*@i',
            '@^tel\:.*@i',
            '@^fax\:.*@i',
            '@.*(\.pdf)$@i'
        ];

        foreach ($stop_links as $ptrn) {
            if (preg_match($ptrn, $uri) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Is URL external?
     * @param  string $url An absolute URL (with scheme)
     * @return bool
     */
    protected function checkIfExternal($url)
    {
        $baseUrlTrimmed = str_replace(array('http://', 'https://'), '', $this->baseUrl);
        $baseUrlTrimmed = explode('/', $baseUrlTrimmed)[0];

        $ret = preg_match("@http(s)?\://$baseUrlTrimmed@", $url) !== 1;
        return $ret;
    }

    /**
     * logging activity of the crawler in case logger is associated
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log($level, $message, array $context = array())
    {
        if (isset($this->logger) === true) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Normalize link (remove hash, etc.)
     * @param  string $uri
     * @return string
     */
    protected function normalizeLink($uri)
    {
        return preg_replace('@#.*$@', '', $uri);
    }

    /**
     * extrating the relative path from url string
     * @param  string $urlRaw
     * @param  string $sourceUrl
     * @return string
     */
    protected function getPathFromUrl($urlRaw, $sourceUrl = null)
    {
        $url = trim($urlRaw);
        if (is_null($sourceUrl)===true) {
            $sourceUrl = $this->baseUrl;
        }

        if ($this->checkIfCrawlable($url) === false) {
            $ret = $url;
        } else {
            $schemaAndHost = parse_url($sourceUrl, PHP_URL_SCHEME).'://'.
            parse_url($sourceUrl, PHP_URL_HOST);

            if (strpos($url, $schemaAndHost) === 0 && $url !== $schemaAndHost) {
                $ret = str_replace($schemaAndHost, '', $url);
            } elseif (strpos($url, 'http://')===0 || strpos($url, 'https://')===0) { //different domain name
                $ret = $url;
            } elseif (strpos($url, '/')!==0) {
                $urlPath = parse_url($sourceUrl, PHP_URL_PATH);
                $extension = pathinfo($urlPath, PATHINFO_EXTENSION);
                if(empty($extension) === false){
                    $baseName = pathinfo($urlPath, PATHINFO_BASENAME);
                    $urlPath = str_replace($baseName,'',$urlPath);
                }
                $path = rtrim($urlPath, '/');
                $ret = $path.'/'.$url;
            } else {
                $ret = $url;
            }
        }
        $ret = $this->removeDotsFromPath($ret);

        return $ret;
    }

    /**
     * remove dots from url
     * @param string $url
     * @return string
     */
    protected function removeDotsFromPath($url){

        if(strpos($url,'/../')!==false){
            $parts = explode('/',$url);
            while($k = array_search('..',$parts)){ //handle ".." in links
                unset($parts[$k-1]);
                unset($parts[$k]);
                $parts = array_values($parts);
            }

            $url = implode('/', $parts);
        }

        return $url;
    }

    /**
     * converting nodeUrl to absolute url form
     * @param string      $nodeUrl
     * @param string|NULL $parentUrl
     * @return string
     */
    protected function getAbsoluteUrl($nodeUrl, $parentUrl = null)
    {

        $urlParts = parse_url($this->baseUrl);

        if (strpos($nodeUrl, 'http://')===0 || strpos($nodeUrl, 'https://')===0) {
                $ret = $nodeUrl;
        } elseif (strpos($nodeUrl, '#') === 0) {
            $ret = rtrim($this->baseUrl, '/').$nodeUrl;
        } elseif (!$this->checkIfCrawlable($nodeUrl)) {
            $ret = $nodeUrl;
        } elseif (strpos($nodeUrl, '//') === 0) {
                $ret = (isset($urlParts['scheme'])=== true?
                        $urlParts['scheme']:'http').':'.$nodeUrl;
        } elseif (isset($urlParts['scheme'])) {
            if (strpos($nodeUrl, '/')===0) {
                $ret = $urlParts['scheme'] . '://' . $urlParts['host'] . $nodeUrl;
            } else {
                $ret = $this->baseUrl.$nodeUrl;
            }
        } elseif ($this->localFile===true) {
            if(strpos($nodeUrl,$parentUrl)===false && empty($parentUrl) === false){
                $ret = dirname($parentUrl).'/'.$nodeUrl;
            }else{
                $ret = $nodeUrl;
            }
        }

        $ret = $this->removeDotsFromPath($ret);

        return $ret;
    }

    /**
     * configure guzzle objects
     * @return array
     */
    protected function configureGuzzleOptions()
    {
        $cookieName = time()."_".substr(md5(microtime()), 0, 5).".txt";

        $defaultConfig = [
            'curl' => [
                CURLOPT_COOKIEJAR      => $cookieName,
                CURLOPT_COOKIEFILE     => $cookieName,
            ],
        ];

        return $defaultConfig;
    }
}
