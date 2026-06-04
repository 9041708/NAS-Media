<?php
class TmdbApi
{
    private string $apiKey;
    private string $language;
    private string $baseUrl;
    private string $imgBase;

    public function __construct()
    {
        $config = require __DIR__ . '/../config.php';
        $this->apiKey  = $config['tmdb']['api_key'];
        $this->language = $config['tmdb']['language'];
        $this->baseUrl = $config['tmdb']['base_url'];
        $this->imgBase = $config['tmdb']['img_base'];
    }

    public function searchMovie(string $query, int $year = null): array
    {
        $params = ['query' => $query, 'language' => $this->language];
        if ($year) {
            $params['year'] = $year;
        }
        $result = $this->request('/search/movie', $params);
        return $result['results'] ?? [];
    }

    public function searchTv(string $query): array
    {
        $result = $this->request('/search/tv', [
            'query'    => $query,
            'language' => $this->language,
        ]);
        return $result['results'] ?? [];
    }

    public function getMovieDetails(int $tmdbId): ?array
    {
        $result = $this->request("/movie/$tmdbId", [
            'language'                    => $this->language,
            'append_to_response'          => 'credits,videos,images',
        ]);
        return $result ?: null;
    }

    public function getTvDetails(int $tmdbId): ?array
    {
        $result = $this->request("/tv/$tmdbId", [
            'language'           => $this->language,
            'append_to_response' => 'credits,videos,images',
        ]);
        return $result ?: null;
    }

    public function getMovieByImdb(string $imdbId): ?array
    {
        $find = $this->request("/find/$imdbId", [
            'external_source' => 'imdb_id',
            'language'        => $this->language,
        ]);
        if (!empty($find['movie_results'])) {
            $tmdbId = $find['movie_results'][0]['id'];
            return $this->getMovieDetails($tmdbId);
        }
        return null;
    }

    public function getTrending(string $mediaType = 'all', string $timeWindow = 'week'): array
    {
        $result = $this->request("/trending/$mediaType/$timeWindow", [
            'language' => $this->language,
        ]);
        return $result['results'] ?? [];
    }

    public function getPopularMovies(int $page = 1): array
    {
        return $this->request('/movie/popular', [
            'language' => $this->language,
            'page'     => $page,
        ]);
    }

    public function getSimilar(int $tmdbId, string $type = 'movie'): array
    {
        $endpoint = $type === 'tv' ? "/tv/$tmdbId/similar" : "/movie/$tmdbId/similar";
        $result = $this->request($endpoint, ['language' => $this->language]);
        return $result['results'] ?? [];
    }

    public function getRecommendations(int $tmdbId, string $type = 'movie'): array
    {
        $endpoint = $type === 'tv' ? "/tv/$tmdbId/recommendations" : "/movie/$tmdbId/recommendations";
        $result = $this->request($endpoint, ['language' => $this->language]);
        return $result['results'] ?? [];
    }

    public function getCastPhotos(int $tmdbId, string $type = 'movie'): array
    {
        $endpoint = $type === 'tv' ? "/tv/$tmdbId" : "/movie/$tmdbId";
        $result = $this->request($endpoint, [
            'language' => $this->language,
            'append_to_response' => 'credits',
        ]);
        if (empty($result['credits']['cast'])) return [];
        $cast = [];
        foreach (array_slice($result['credits']['cast'], 0, 30) as $c) {
            $cast[] = [
                'name' => $c['name'] ?? '',
                'character' => $c['character'] ?? '',
                'profile_path' => $c['profile_path'] ?? null,
            ];
        }
        return $cast;
    }

    public function getSeasonDetails(int $tvId, int $seasonNum): ?array
    {
        $result = $this->request("/tv/$tvId/season/$seasonNum", [
            'language' => $this->language,
        ]);
        return $result ?: null;
    }

    public function getEpisodeDetails(int $tvId, int $seasonNum, int $episodeNum): ?array
    {
        $result = $this->request("/tv/$tvId/season/$seasonNum/episode/$episodeNum", [
            'language' => $this->language,
        ]);
        return $result ?: null;
    }

    public function getMovieVideos(int $tmdbId): array
    {
        $result = $this->request("/movie/$tmdbId/videos", ['language' => $this->language]);
        return $result['results'] ?? [];
    }

    public function getTvVideos(int $tmdbId): array
    {
        $result = $this->request("/tv/$tmdbId/videos", ['language' => $this->language]);
        return $result['results'] ?? [];
    }

    public function searchPerson(string $name): ?array
    {
        $result = $this->request('/search/person', [
            'query'    => $name,
            'language' => $this->language,
        ]);
        return $result['results'][0] ?? null;
    }

    public function getPersonDetails(int $personId): ?array
    {
        $result = $this->request("/person/$personId", [
            'language'           => $this->language,
            'append_to_response' => 'combined_credits',
        ]);
        return $result ?: null;
    }

    public function getTopRatedMovies(int $page = 1): array
    {
        return $this->request('/movie/top_rated', [
            'language' => $this->language,
            'page'     => $page,
        ]);
    }

    public function getPosterUrl(?string $path, string $size = 'w500'): ?string
    {
        if (empty($path)) return null;
        return $this->imgBase . $size . $path;
    }

    public function getBackdropUrl(?string $path, string $size = 'original'): ?string
    {
        if (empty($path)) return null;
        return $this->imgBase . $size . $path;
    }

    public function formatMovieData(array $tmdbData): array
    {
        $genres = [];
        if (!empty($tmdbData['genres'])) {
            $genres = array_column($tmdbData['genres'], 'name');
        } elseif (!empty($tmdbData['genre_ids'])) {
            $genres = $this->resolveGenres($tmdbData['genre_ids']);
        }

        $director = '';
        $cast = [];
        if (!empty($tmdbData['credits']['crew'])) {
            foreach ($tmdbData['credits']['crew'] as $crew) {
                if ($crew['job'] === 'Director') {
                    $director = $crew['name'];
                    break;
                }
            }
        }
        if (!empty($tmdbData['credits']['cast'])) {
            $cast = array_slice(array_map(fn($c) => $c['name'], $tmdbData['credits']['cast']), 0, 20);
        }

        $year = null;
        if (!empty($tmdbData['release_date'])) {
            $year = (int) substr($tmdbData['release_date'], 0, 4);
        } elseif (!empty($tmdbData['first_air_date'])) {
            $year = (int) substr($tmdbData['first_air_date'], 0, 4);
        }

        return [
            'tmdb_id'       => $tmdbData['id'],
            'title'         => $tmdbData['title'] ?? $tmdbData['name'] ?? '',
            'original_title'=> $tmdbData['original_title'] ?? $tmdbData['original_name'] ?? '',
            'year'          => $year,
            'type'          => isset($tmdbData['number_of_seasons']) ? 'tv' : 'movie',
            'overview'      => $tmdbData['overview'] ?? '',
            'poster_path'   => $tmdbData['poster_path'] ?? null,
            'backdrop_path' => $tmdbData['backdrop_path'] ?? null,
            'rating'        => $tmdbData['vote_average'] ?? null,
            'vote_count'    => $tmdbData['vote_count'] ?? 0,
            'genres'        => implode(',', $genres),
            'release_date'  => $tmdbData['release_date'] ?? $tmdbData['first_air_date'] ?? null,
            'runtime'       => $tmdbData['runtime'] ?? null,
            'language'      => $tmdbData['original_language'] ?? null,
            'country'       => !empty($tmdbData['production_countries'])
                ? $tmdbData['production_countries'][0]['name'] ?? '' : '',
            'director'      => $director,
            'cast_list'     => implode(',', $cast),
        ];
    }

    private function resolveGenres(array $genreIds): array
    {
        static $genreMap = [
            28 => '动作', 12 => '冒险', 16 => '动画', 35 => '喜剧', 80 => '犯罪',
            99 => '纪录', 18 => '剧情', 10751 => '家庭', 14 => '奇幻', 36 => '历史',
            27 => '恐怖', 10402 => '音乐', 9648 => '悬疑', 10749 => '爱情', 878 => '科幻',
            53 => '惊悚', 10752 => '战争', 37 => '西部', 10770 => '电视电影',
            10759 => '动作冒险', 10762 => '儿童', 10763 => '新闻', 10764 => '真人秀',
            10765 => '科幻奇幻', 10766 => '肥皂剧', 10767 => '脱口秀', 10768 => '战争政治',
        ];
        $genres = [];
        foreach ($genreIds as $id) {
            if (isset($genreMap[$id])) {
                $genres[] = $genreMap[$id];
            }
        }
        return $genres;
    }

    private function request(string $endpoint, array $params = []): array
    {
        if (empty($this->apiKey)) {
            return [];
        }

        $params['api_key'] = $this->apiKey;
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);

        $cacheKey = md5($url);
        $cacheFile = __DIR__ . '/../cache/tmdb_' . $cacheKey . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            return json_decode(file_get_contents($cacheFile), true);
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return [];
        }

        $data = json_decode($response, true);
        if ($data) {
            $results = $data['results'] ?? null;
            if ($results !== null && count($results) === 0) {
                return $data;
            }
            if (!is_dir(__DIR__ . '/../cache')) {
                mkdir(__DIR__ . '/../cache', 0755, true);
            }
            file_put_contents($cacheFile, $response);
        }

        return $data ?: [];
    }
}
