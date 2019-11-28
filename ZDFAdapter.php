<?php

//
// ZDFAdapter
// 
//
	
require_once 'Adapter.php';

class ZDFAdapter extends Adapter {
    
    public $publisher = 'ZDF';
    
    static private $adapterTestItemIDValue;
    public function adapterTestItemID() {
        
        if (!strlen(self::$adapterTestItemIDValue))  {
            
            // Get item from website
            $html_data = requestDataFromURL('https://www.zdf.de/');
            $pos = strpos($html_data, '/filme/');
            if ($pos === false) throw new Exception('Failed to extract item id in ZDFAdapter::adapterTestItemID().');
            $pos2 = strpos($html_data, '.html', $pos);
            $id = 'zdf'.substr($html_data, $pos, $pos2-$pos);
            
            self::$adapterTestItemIDValue = $id;
            
        }
        
        return self::$adapterTestItemIDValue;
    }
    
    
    public function adapterTestChannelID() {
        return 'heute-journal-104';
    }
    
    private function call2019API($apiURL, $itemID, $type, $recursiveCall = false) {
        $maxCacheAge = self::maxCacheAge($type);
        $tokenFile = __DIR__.'/tokens/api.zdf.de.txt';
        $apiToken = @file_get_contents($tokenFile);
        $response = requestResponseFromURL($apiURL,[
                                'Accept: application/vnd.de.zdf.v1.0+json',
                                'Origin: https://www.zdf.de',
                                'Host: api.zdf.de',
                                'Referer: https://www.zdf.de/dokumentation/terra-x',
                                'Api-Auth: Bearer '.$apiToken,
                                ], 'zdf_'.$type.'_'.self::sanitizeCacheID($itemID).'.json', $maxCacheAge);
        
        $apiDataRaw = $response->body;
        $statusCode = $response->statusCode;
        
        if ('Authentication failed' == $apiDataRaw || $response->statusCode == 403) {
            
            if ($recursiveCall || strlen($itemID) == 0) throw $this->createError('We need a new API token. Server responded with: Authentication failed. Current API token = '.$apiToken.', requested URL = '.$apiURL.' . Automated fetch from website failed.');
            
            // Extract the new API token directly from the website's HTML.
            // This is a risky approach, since the website design could change at any time.
            //
            $webURL = 'https://www.zdf.de/nachrichten/heute-journal';
            $html = requestDataFromURL($webURL);
            $pos = strpos($html, '"apiToken":');
            $pos2 = strpos($html, ',', $pos);
            if (!$pos || !$pos2) throw $this->createError('Failed to get new API token from website.');
            $pos += strlen('"apiToken":');
            $token = str_replace('"','',trim(substr($html,$pos,($pos2-$pos-1))));
            
            
            file_put_contents($tokenFile, $token);
            
            return $this->call2019API($apiURL, $itemID, $type, true);
        }
        if ($apiDataRaw[0] != '{') {
            throw $this->createError('Could not access API ('.$apiURL.') with API token '.$apiToken.'. Got non-JSON response: '.$apiDataRaw);
        }
        
        $apiData = json_decode($apiDataRaw);
        
        if (!$apiData) {
            throw $this->createError('Could not decode JSON.');
        }
        if ($apiData->error) {
            throw $this->createError('API responded with error: '.json_encode($apiData));
        }
        return $apiData;
    }
    
    private function createImageVariant($url, $width, $height) {
        $variant = new StdClass;
        $variant->url = $url;
        if (is_numeric($width))
            $variant->width = $width*1;
        if (is_numeric($height))
            $variant->height = $height*1;
        return $variant;
    }
    
    public function readItemByID($id) {
        
        // profile=default leads to a huge response, 1-2MB JSON, we take profile=player2.
        $apiURL = 'https://api.zdf.de/content/documents/'.$id.'.json?profile=player2';
        $apiData = $this->call2019API($apiURL, $id, 'item');
        
        if (!$apiData) throw new Exception('API responded with empty body (item id = '.$id.')');
        if (!is_string($apiData->title)) throw new Exception('Unexpected API response (item id = '.$id.'): '.json_encode($apiData));
        
        $item = new Item;
        $item->id = $id;
        $item->publisher = $this->publisher;
        $item->title = $apiData->title;
        $this->optimizeTitle($item->title);
        $item->description = trim($apiData->leadParagraph);
        
        $item->contributor = $apiData->tvService;

        $item->airtime = strtotime($apiData->editorialDate); // can be in the future.
        
        $item->channel = new Channel;
        $item->channel->id = $apiData->{'http://zdf.de/rels/brand'}->{'http://zdf.de/rels/target'}->id;
        $item->channel->name = $apiData->{'http://zdf.de/rels/brand'}->title;
        
        
        // thumbnail
        $image = new StdClass;
        $image->description = $apiData->teaserImageRef->caption;
        $image->copyright = $apiData->teaserImageRef->copyrightNotice;
        $image->variants = [];
        if ($apiData->teaserImageRef->layouts) {
            foreach ($apiData->teaserImageRef->layouts as $key=>$value) {
                if ($key == 'original') continue;
                $size = explode('x',$key);
                $image->variants[] = $this->createImageVariant($value,$size[0],$size[1]);
            }
        }
        
        $item->image = $image;
        
        
        $videoContent = $apiData->mainVideoContent;
        if (!$videoContent) $videoContent = $apiData->mainContent->videoContent;
        if (!$videoContent) {
            //print_r($apiData);exit;
            return $item;
        }
        
        
        $videoContent = $videoContent->{'http://zdf.de/rels/target'};
        
        $validTo = strtotime($videoContent->visibleTo);
        if($validTo) $item->validTo = $validTo;
        
        $item->valid = (boolean)$videoContent->visible;
        $item->duration = $videoContent->duration;
        
        // media
        $item->media = [];
        $urlTemplate = $videoContent->{'http://zdf.de/rels/streams/ptmd-template'};
        if (strlen($urlTemplate)) {
            $url = 'https://api.zdf.de'.str_replace('{playerId}', 'ngplayer_2_3', $urlTemplate);
            $videoInfo = $this->call2019API($url, $id, 'itemmedia');
            if ($videoInfo->priorityList) {
                foreach ($videoInfo->priorityList as $list) {
                    foreach ($list->formitaeten as $formitaet) {
                        foreach ($formitaet->qualities as $qualityInfo) {
                            foreach ($qualityInfo->audio->tracks as $trackInfo) {
                                $bitrate = 0; // Unknown.
                                if ($qualityInfo->quality == 'veryhigh') $bitrate = 15000;
                                if ($quality == 'high') $bitrate = 10000;
                                if ($quality == 'med') $bitrate =  9000;
                                if ($quality == 'low') continue; // skipping low
                                $url = $trackInfo->uri;
                                if (strpos($url, 'manifest.f4m') !== false) continue;
                                if (strpos($url, '.webm') !== false) continue;
                                if(strpos($url, '_hd.') !== false) $item->HD = true;
                                
                                
                                $info = new StdClass;
                                $info->url = $url;
                                $info->type = strpos($url,'.m3u8') !== false ? 'application/x-mpegURL' : 'video/mp4';;
                                $info->bitrate = $bitrate;
                                $info->comment = 'Quality '.$qualityInfo->quality;
                                $item->media[] = $info;
                                
                            }
                        }
                    }
                }
            }
        }
        
        return $item;
    }
    
    /**
     * Get item by a specific URL from this publisher
     *
     * @return object The item
     */
    
    public function readItemByURL($URL) {
        
        
        // get the video ID from the URL
        $path = parse_url($URL, PHP_URL_PATH);
        ($video_id = basename($path)) || ($video_id = dirname($path));
        //$video_id = substr($path,1);
        $video_id = str_replace('.html','',$video_id);
        
        return $this->readItemByID($video_id);
    }
    
    /**
     * Update the channels for this publisher
     *
     */
    public function readListOfChannels() {
        
        $apiURL = 'https://api.zdf.de/content/documents/sendungen-100.json?profile=default';
        $apiData = $this->call2019API($apiURL, 'zdf_channellist_sendungen-100', 'channels'); // > 1 MB
        
        //file_put_contents('/Users/jonathan/Desktop/readChannelsList.json',json_encode($apiData));exit;
        
        $channels = [];
        
        foreach ($apiData->brand as $collection) {
            if (!$collection->teaser) continue;
            foreach ($collection->teaser as $teaser) {
                //if (count($channels) == 60) { print_r($teaser);exit; }
                
                $channel = new Channel;
                
                $channel->name = (string)$teaser->title;
                $channel->description = (string)$teaser->{'http://zdf.de/rels/target'}->teasertext;
                
                $channel->id = (string)$teaser->{'http://zdf.de/rels/target'}->id;
                $channel->publisher = 'ZDF';
                $channel->contributor = (string)$teaser->{'http://zdf.de/rels/target'}->{'http://zdf.de/rels/content/conf-section'}->homeTvService->tvServiceTitle;
                
                $channel->homepage = (string)$teaser->{'http://zdf.de/rels/target'}->{'http://zdf.de/rels/sharing-url'};
                
                //Adapter::addChannelLegacyData($channel);
                //print_r(json_encode($channel)); exit;
                
                $channel->image = [];
                
                $imageVariants = $teaser->{'http://zdf.de/rels/target'}->teaserImageRef->layouts;
                //if ($channel->name == 'maybrit illner') {print_r($imageVariants);exit;}
                if ($imageVariants->{'640x720'}) {
                    $image = new StdClass;
                    $image->width = 3000;
                    $image->height = 3000;
                    $image->url = $imageVariants->{'640x720'};
                    $channel->image[] = $image;
                }
                if ($imageVariants->{'3000x3000'}) {
                    $image = new StdClass;
                    $image->width = 3000;
                    $image->height = 3000;
                    $image->url = $imageVariants->{'3000x3000'};
                    $channel->image[] = $image;
                }
                if ($imageVariants->{'768x432'}) {
                    $image = new StdClass;
                    $image->width = 768;
                    $image->height = 432;
                    $image->url = $imageVariants->{'768x432'};
                    $channel->image[] = $image;
                }
                
                $channels[] = $channel;
            }
        }
        
        return $channels;
        
    }
    
    /**
     * Get metadata of a channel
     *
     * @param ID The ID of the channel
     * @return object The channel
     */
    // example id: terra-x-112
    public function readChannel($id)
    {
        
        $apiURL = 'https://api.zdf.de/content/documents/'.$id.'.json?profile=default';
        $apiData = $this->call2019API($apiURL, $id, 'channelmeta');

                    
        $channel = new Channel;
        $channel->id = $apiData->{'http://zdf.de/rels/brand'}->{'http://zdf.de/rels/target'}->id;
        $channel->name = $apiData->{'http://zdf.de/rels/brand'}->title;
        $channel->publisher = $this->publisher;
        $channel->contributor = (string)$teaser->{'http://zdf.de/rels/target'}->{'http://zdf.de/rels/content/conf-section'}->homeTvService->tvServiceTitle;
        
        // API data doesn't reliably have a description here. We take the RSS feed for it.
        $xml = $this->getRSSFeedForChannel($apiData->structureNodePath);
        if ($xml) {
            //print_r($xml->channel);exit;
            $channel->description = (string)$xml->channel->description;
        }
        
        $channel->homepage = $apiData->{'http://zdf.de/rels/sharing-url'};
        $channel->image = [];
        
        $imageVariants = $apiData->teaserImageRef->layouts;
        
        if ($imageVariants->{'640x720'}) {
            $image = new StdClass;
            $image->width = 3000;
            $image->height = 3000;
            $image->url = $imageVariants->{'640x720'};
            $channel->image[] = $image;
        }
        if ($imageVariants->{'3000x3000'}) {
            $image = new StdClass;
            $image->width = 3000;
            $image->height = 3000;
            $image->url = $imageVariants->{'3000x3000'};
            $channel->image[] = $image;
        }
        if ($imageVariants->{'768x432'}) {
            $image = new StdClass;
            $image->width = 768;
            $image->height = 432;
            $image->url = $imageVariants->{'768x432'};
            $channel->image[] = $image;
        }
        
        return $channel;
    }
    
    
    private function getRSSFeedForChannel($structureNodePath) {
        
        $rssURL = 'https://www.zdf.de/rss'.$structureNodePath;
        
        $xml_data = requestDataFromURL($rssURL, [], 'zdf_feed_'.self::sanitizeCacheID($structureNodePath).'.rss', self::maxCacheAge('feed'));
        
        if ($xml_data[0] == '{') {
            throw $this->createError('ZDF RSS feed is broken, got response: '.$xml_data);
        }
        
        $xml = simplexml_load_string($xml_data);
        if(!$xml) {
            throw $this->createError('Internal error: RSS feed not found.');
        }
        return $xml;
    }
    
    /**
     * Get a channel's feed
     *
     * @param ID The ID of the channel
     * @return array A list of item IDs
     */
    public function readChannelFeed($id)
    {
        $apiURL = 'https://api.zdf.de/content/documents/'.$id.'.json?profile=player2';
        $apiData = $this->call2019API($apiURL, $id, 'channel');
        
        $structureNodePath = $apiData->structureNodePath;
        
        $xml = $this->getRSSFeedForChannel($structureNodePath);
        
        $feed = array();
        foreach($xml->channel->item as $item) {
            $URL = (string)$item->link;
            $pieces = explode('/', $URL);
            $ID = array_pop($pieces);
            $ID = str_replace('.html','',$ID);
            if($ID) $feed[] = $ID;
        }
        
        return $feed;
        
    }
    
};


