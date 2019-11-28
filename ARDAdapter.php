<?php

//
// ARDAdapter
// 
//

require_once 'Adapter.php';


class ARDAdapter extends Adapter {
	
	public $publisher = "ARD";


	/**
	 * Get item by publisher-dependent ID
	 *
	 * @param ID The ID of the item
	 * @return Item The item
	 */
	public function readItemByID($id) {

		// Unfortunately this API call doesn't provide us with 'availableTo' and 'broadcastedOn',
		// which is only present in the 'showId' feed.
		$apiData = $this->call2019API(
			["client"=>"ard","clipId"=>$id,"deviceType"=>"pc"],
			$this->getQueryHash('item'),
			'ard_item_'.$id,
			self::maxCacheAge('item')
		);


		if (!$apiData) throw new Exception('API responded with empty body (item id = '.$id.')');
		if (!$apiData->data || !$apiData->data->playerPage)
			throw new Exception('Unexpected API response (item id = '.$id.'): '.json_encode($apiData));

		$item = new Item;
		$item->publisher = $this->publisher;
		$item->url = 'https://www.ardmediathek.de/ard/player/'.$id;
		$item->id = $id;

		$item->title = (string)$apiData->data->playerPage->title;
		$item->title = str_replace(' (FSK | tgl. ab 20 Uhr)', '', $item->title);
		if($item->title == 'Morgenmagazin') $item->title = 'ARD Morgenmagazin';
		else $this->optimizeTitle($item->title);
		$item->description = (string)$apiData->data->playerPage->synopsis;
		$item->contributor = $apiData->data->playerPage->publicationService->name;
		$item->geoblocked = $apiData->data->playerPage->geoblocked;
		$item->duration = $apiData->data->playerPage->mediaCollection->_duration;

		// media
		$item->media = [];
		$array = $apiData->data->playerPage->mediaCollection->_mediaArray[0]->_mediaStreamArray;
		if ($array) foreach ($apiData->data->playerPage->mediaCollection->_mediaArray[0]->_mediaStreamArray as $o) {
			foreach ($o->_stream as $url) {
				$info = new StdClass;
				if ($url[0] == '/') $url = 'https:'.$url;
				$info->url = $url;
				$info->type = $o->_quality == 'auto' ? 'application/x-mpegURL' : 'video/mp4';
				$info->bitrate = self::avgBitrateForQuality($o->_quality);
				$info->comment = 'Quality '.$o->_quality;
				$item->media[] = $info;
			}
		}

		// add date to 'Ganze Sendung' (ct Magazin)
		if($item->airtime && $item->title == 'Ganze Sendung') {
			$item->title = $item->title . ' vom ' . $test;
		}


		// thumbnail
		$imageURL = $apiData->data->playerPage->image->src;
		$image = new StdClass;
		$image->description = $apiData->data->playerPage->image->alt;
		$image->variants = $this->getImageVariants($imageURL,'item');
		$item->image = $image;
		
		// series
		$item->channel = new Channel;
		$item->channel->name = $apiData->data->playerPage->show->title;
		$item->channel->id = $apiData->data->playerPage->show->id;

		// airtime
		$item->airtime = strtotime($apiData->data->playerPage->broadcastedOn);

		// expires
		$item->expires = strtotime($apiData->data->playerPage->availableTo);

		// valid-to missing
		// FSK missing

		return $item;

	}
	

	/**
	 * Get item by a specific URL from this publisher
	 *
	 * @param URL The URL of the item
	 * @return object The item
	 */
	public function readItemByURL($url) {

		$urlInfo = parse_url($url);
		if ($urlInfo['host'] != 'www.ardmediathek.de')
			throw $this->createError("Unexpected host, should be 'www.ardmediathek.de' but is '{$urlInfo['host']}'.");

		$pathParts = explode('/',$urlInfo['path']);
		if ($pathParts[1] != 'ard' || $pathParts[2] != 'player')
			throw $this->createError("Unexpected URL scheme, should be 'www.ardmediathek.de/ard/player/ID'.");

		return $this->readItemByID($pathParts[3]);

	}

	private function call2019API($variables, $sha256Hash, $cacheID, $maxCacheAge) {

		$extensions = ["persistedQuery"=>["version"=>1,"sha256Hash"=>$sha256Hash]];

		$apiURL = 'https://api.ardmediathek.de/public-gateway?variables='.urlencode(json_encode($variables)).'&extensions='.urlencode(json_encode($extensions));
		//print $apiURL; exit;
		$apiDataRaw = requestDataFromURL($apiURL, [], self::sanitizeCacheID($cacheID).'.json', $maxCacheAge);
		$apiData = json_decode($apiDataRaw);
		if (!$apiDataRaw || !$apiData) {
			throw $this->createError('Could not call API.');
		}
		if ($apiData->error) {
			throw $this->createError('API responded with error: '.json_encode($apiData));
		}

		return $apiData;

	}

	private function createImageVariant($imageURLTemplate, $width, $height) {
		$variant = new StdClass;
		$variant->url = str_replace('{width}', $width, $imageURLTemplate);
		$variant->width = $width;
		$variant->height = $height;
		return $variant;
	}

	private function getImageVariants($imageURLTemplate, $type) {
		if ($type == 'item') {
			return [
				$this->createImageVariant($imageURLTemplate, 1984, 1116),
				$this->createImageVariant($imageURLTemplate, 1024, 576),
				$this->createImageVariant($imageURLTemplate, 640, 360),
				$this->createImageVariant($imageURLTemplate, 256, 144),
			];
		} else if ($type == 'channel') {
			return [
				$this->createImageVariant($imageURLTemplate, 320, 180),
				$this->createImageVariant($imageURLTemplate, 768, 432),
			];
		}
	}



	/**
	 * Get metadata of a channel
	 *
	 * @param ID The ID of the channel
	 * @return object The channel
	 */
	public function readChannel($id)
	{

		$apiData = $this->call2019API(
			["client"=>"ard","showId"=>$id,"deviceType"=>"pc"],
			$this->getQueryHash('show'),
			'ard_show_'.$id,
			self::maxCacheAge('channel')
		);

		$channel = new Channel;
		$channel->id = $id;
		$channel->name = $apiData->data->showPage->title;
		$channel->description = $apiData->data->showPage->synopsis;
		$channel->publisher = $apiData->data->showPage->publicationService->name;
		$channel->image = $this->getImageVariants($apiData->data->showPage->image->src,'channel');
		return $channel;

	}

	

	/**
	 * Get a channel's feed
	 *
	 * @param ID The ID of the channel
	 * @return array A list of item IDs
	 */
	public function readChannelFeed($id)
	{

		$apiData = $this->call2019API(
			["client"=>"ard","showId"=>$id,"pageNumber"=>0],
			$this->getQueryHash('feed'),
			'ard_feed_'.$id.'_1',
			self::maxCacheAge('feed')
		);

		//print_r($apiData);exit;

		$feed = array();
		if (is_array($apiData->data->showPage->teasers)) {
			foreach($apiData->data->showPage->teasers as $teaser) {
				$feed[] = $teaser->id;
			}
		}
		
		return $feed;
	}



	/**
	 * Get all available the channels for this publisher
	 *
	 */
	public function readListOfChannels()
	{

		$letters = "#ABCDEFGHIJKLMNOPQRSTUVWXYZ";

		$queryHashes = [
			'#'=>'',
			'A'=>'3bfe84dc9887d0991263fb19dc4c5ba501bb5f27db0a06074b9b0e9ecf2c3c27',
			'B'=>'557b3d0694f7d8d589e43c504a980f4090a025b8c2eefa6559b245f2f1a69e16',
			'C'=>'4a35671fa57762f7e94a2aa79dc48f7fa9dde7c25387ecf9b722d37b26cc2d95',
			'D'=>'f942fa0fe653a179d07349a907687544b090751deabe848919fc10949b3e05c6',
			'E'=>'b7c5db273782bed01ae8ed000d7b5c7b6fdacad30b2d88690b1819c131439a61',
			'F'=>'3fc33abce9a66d020a172a15268354acc4139652c4211be02f95ed470fc34962',
			'G'=>'0ea25f94b3f8f4978bd55189392ed6a1874fe66c846a92734a50d3de37e4dad9',
			'H'=>'fa55e3e6db3952d3cfb5a59fbfe413291fa11fdc07fac77b6f97d50478c9e201',
			'I'=>'b5f9682e177cd52d7e1b02800271f0f2128ba738b58e3f8896b0bbfe925d4d72',
			'J'=>'6da769a89ec95b2a50f4c751eb8935e42d826fa26946a2fa0e842e332883473f',
			'K'=>'ac31e2cf0e381196de7e32ceeedfd1a53d67f5b926d86e37763bd00a6d825be3',
			'L'=>'81668bf385abcf876495cdf4280a83431787c647fa42defb82d3096517578ab3',
			'M'=>'7277a409abd703c9c2858834d93a18fdfce0ea0aee3a416a6bdea62a7ac73598',
			'N'=>'dc8b7e99c2aa1397e658fb380fe96d7fb940d18b895c2336f3284751898d48c7',
			'O'=>'7a3a675566f5b17594eb2027ec46b6e9de70da141f8793970fae1d22df3b22c3',
			'P'=>'3a3c88b51baddc0e9a2d1bb7888e4d44ec8901d0f5f448ca477b36e77aac8efd',
			'Q'=>'5ad27bbec3d8fbc6ea7dc74f3cae088f2160120b4a7659ba5ed62e950301a0b6',
			'R'=>'7e8cd2c0c128019fe0885cc61b5320867ec211dcd2f0986238da07598d826587',
			'S'=>'a56ae9754a77be068bc3d87c8bf0d8229a13bd570d4230776bfbb91c0496a022',
			'T'=>'048cd18997a847069d006adf86879944e1b5069ff2258e5cb3c1a37d2265b91e',
			'U'=>'cc8ae75b395d3faa3b338e19815af7d6af4ad8c5f462e1163b2fa8bae5404a54',
			'V'=>'a348091704377530f2b4db50cdf4287859424855aad21d99c64f8454c602698a',
			'W'=>'1c8d95d7f0f74fe53f6021ef9146183f19ababd049b31e0b9eb909ffcf86d6c0',
			'X'=>'',
			'Y'=>'8bc949cd1652c68b4ff28ac9d38c5450fe6e42783428135fe65af3f230414668',
			'Z'=>'cc7a222db4cc330c2a5a74f8cd64157f255dcfec9272b7fe8f742d2e489aae8f',
		];

		$channels = array(); $pos = 0;
		for ($i = 0, $c = strlen($letters); $i < $c; $i++) {
			$letter = $letters[$i];
			if (!$queryHashes[$letter]) continue;

			$apiData = $this->call2019API(
				["client"=>"ard"],
				$queryHashes[$letter],
				'ard_channel_list_'.$letter,
				self::maxCacheAge('channel-list')
			);

			$glossary = $apiData->data->showsPage->glossary;

			foreach ($glossary as $key=>$v) {
				if ($key[0]=='_') continue;
				if (!is_array($glossary->{$key})) { continue; }
				foreach ($glossary->{$key} as $show) {

					$channel = new StdClass;
					$channel->id = $show->id;
					$channel->name = $show->mediumTitle;
					$channel->publisher = $this->publisher;
					$channel->contributor = $show->publicationService->name;
					$channel->image = $this->getImageVariants($show->images->{'aspect16x9'}->src,'channel');
					if(!$channel->name) continue;
					$channels[] = $channel;
				}
			}

		}
		
		return $channels;

	}


	/**
	 * Get SHA256 hash for the 2019 API
	 *
	 * @param ID The ID of the item
	 * @return Item The item
	 */
	private function getQueryHash($type) {

		if ($type == 'show') {
			return 'e98095b5fed901f947f5c6683b82514fad519e7c96db065a52a60f92fbd4591f';
		}

		if ($type == 'item') {
			return 'b69efa74d0e2623a9104fb94c9ed2e8f1418a68f6457594126d719a2e8ca7174';

		}

		if ($type == 'feed') {
			return '747b8db78443f20a0deb73a8e89ae9b0d26fcf83f2fc732181649698a0875cff';
		}

		throw $this->createError('Unsupported type!');
	}


	/**
	 * Returns a item ID to run a test on the adapter.
	 *
	 * @return String 
	 */
	public function adapterTestItemID() {

		if (!strlen(self::$adapterTestItemIDValue))  {

			// Get item from website
			$html_data = requestDataFromURL('http://www.ardmediathek.de/');
			$pos = strpos($html_data, '/ard/player/');
			if ($pos === false) throw new Exception('Failed to extract item id in ARDAdapter::adapterTestItemID().');
			$pos += strlen('/ard/player/');
			$pos2 = strpos($html_data, '/', $pos);
			$id = substr($html_data, $pos, $pos2-$pos);

			self::$adapterTestItemIDValue = $id;

		}

		return self::$adapterTestItemIDValue;
	}

	static private $adapterTestItemIDValue;


	/**
	 * Returns a channel ID to run a test on the adapter.
	 *
	 * @return String 
	 */
	public function adapterTestChannelID() {
		// Tagesschau
		return 'Y3JpZDovL2Rhc2Vyc3RlLmRlL3RhZ2Vzc2NoYXU'; 
	}

	/**
	 * Gets the average (measured) bitrates for the different quality values from ARD.
	 *
	 * @return Boolean 
	 */
	private static function avgBitrateForQuality($q) {
		if ($q === '0') $bitrate = 180;
		if ($q === '1') $bitrate = 600;
		if ($q === '2') $bitrate = 1200;
		if ($q === '3') $bitrate = 2000;
		return $bitrate;
	}

};
