<?php
/**
 * FullTextSearch_ElasticSearch - Use Elasticsearch to index the content of your nextcloud
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\FullTextSearch_ElasticSearch\Service;

use OCA\FullTextSearch\IFullTextSearchProvider;
use OCA\FullTextSearch\Model\DocumentAccess;
use OCA\FullTextSearch\Model\SearchRequest;
use OCA\FullTextSearch_ElasticSearch\Exceptions\ConfigurationException;


class SearchMappingService {

	/** @var ConfigService */
	private $configService;

	/** @var MiscService */
	private $miscService;


	/**
	 * MappingService constructor.
	 *
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(ConfigService $configService, MiscService $miscService) {
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 * @param IFullTextSearchProvider $provider
	 * @param DocumentAccess $access
	 * @param SearchRequest $request
	 *
	 * @return array
	 * @throws ConfigurationException
	 */
	public function generateSearchQuery(
		IFullTextSearchProvider $provider, DocumentAccess $access, SearchRequest $request
	) {
		$query['params'] = $this->generateSearchQueryParams($provider, $access, $request);

		return $query;
	}


	/**
	 * @param IFullTextSearchProvider $provider
	 * @param DocumentAccess $access
	 * @param SearchRequest $request
	 *
	 * @return array
	 * @throws ConfigurationException
	 */
	public function generateSearchQueryParams(
		IFullTextSearchProvider $provider, DocumentAccess $access, SearchRequest $request
	) {
		$str = strtolower($request->getSearch());

		$params = [
			'index' => $this->configService->getElasticIndex(),
			'type'  => $provider->getId(),
			'size'  => $request->getSize(),
			'from'  => (($request->getPage() - 1) * $request->getSize())
		];

		$bool = [];
		$bool['must']['bool']['should'] =
			$this->generateSearchQueryContent($str);
		$bool['filter'][]['bool']['should'] =
			$this->generateSearchQueryAccess($access);
		$bool['filter'][]['bool']['should'] =
			$this->generateSearchQueryTags($request->getTags());

		$params['body']['query']['bool'] = $bool;
		$params['body']['highlight'] = $this->generateSearchHighlighting();

		return $params;
	}


	/**
	 * @param string $string
	 *
	 * @return array<string,array<string,array>>
	 */
	private function generateSearchQueryContent($string) {
		$queryTitle = $queryContent = [];
		$words = explode(' ', $string);
		foreach ($words as $word) {

			$kw = 'prefix';
			$this->modifySearchQueryContentOnCompleteWord($kw, $word);

			array_push($queryTitle, [$kw => ['title' => $word]]);
			array_push($queryContent, [$kw => ['content' => $word]]);
		}

		return [
			['bool' => ['must' => $queryTitle]],
			['bool' => ['must' => $queryContent]]
		];
	}


	/**
	 * @param string $kw
	 * @param string $word
	 */
	private function modifySearchQueryContentOnCompleteWord(&$kw, &$word) {
		if (substr($word, 0, 1) !== '"' || substr($word, -1) !== '"') {
			return;
		}

		$kw = 'match';
		$word = substr($word, 1, -1);
	}


	/**
	 * @param DocumentAccess $access
	 *
	 * @return array<string,array>
	 */
	private function generateSearchQueryAccess(DocumentAccess $access) {

		$query = [];
		$query[] = ['match' => ['owner' => $access->getViewerId()]];
		$query[] = ['match' => ['users' => $access->getViewerId()]];
		$query[] = ['match' => ['users' => '__all']];

		foreach ($access->getGroups() as $group) {
			$query[] = ['match' => ['groups' => $group]];
		}

		foreach ($access->getCircles() as $circle) {
			['match' => ['circles' => $circle]];
		}

		return $query;
	}


	/**
	 * @param array $tags
	 *
	 * @return array<string,array>
	 */
	private function generateSearchQueryTags($tags) {

		$query = [];
		foreach ($tags as $tag) {
			$query[] = ['term' => ['tags' => $tag]];
		}

		return $query;
	}

	private function generateSearchHighlighting() {
		return [
			'fields'    => ['content' => new \stdClass()],
			'pre_tags'  => [''],
			'post_tags' => ['']
		];
	}


}
