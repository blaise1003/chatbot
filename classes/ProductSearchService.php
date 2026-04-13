<?php

namespace Chatbot;

class ProductSearchService
{
    private $token;
    private $searchUrl;

    public function __construct($token, $searchUrl)
    {
        $this->token = $token;
        $this->searchUrl = $searchUrl;
    }

    public function getProductsByKeyword($keyword)
    {
        if ($this->token === '') {
            return [];
        }

        $url = $this->searchUrl . '?query=' . urlencode($keyword);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Token ' . $this->token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) {
            return [];
        }

        $decoded = json_decode($response, true);
		
        $results = isset($decoded['results']) ? $decoded['results'] : [];
        $products = array();
        $count = 0;

        foreach ($results as $result) {
            if ($count >= 3) {
                break;
            }

            if (!isset($result['id'])) {
                continue;
            }
            $productRes = array(
                'Title' => isset($result['title']) ? htmlspecialchars($result['title']) : '',
                'Image' => htmlspecialchars($this->resolveImageUrl($result)),
                'Price' => (isset($result['best_price']) ? htmlspecialchars($result['best_price']) : '') . ' €',
                'Url' => isset($result['link']) ? htmlspecialchars($result['link']) : '',
                'Reference' => (string) (isset($result['id']) ? htmlspecialchars($result['id']) : ''),
                'Description' => isset($result['description']) ? htmlspecialchars($result['description']) : ''
			);

			$products[] = $productRes;
            $count++;
        }

        return $products;
    }

    private function resolveImageUrl(array $result)
    {
        if (!empty($result['image_link'])) {
            return $result['image_link'];
        }

        if (!empty($result['image_url'])) {
            return $result['image_url'];
        }

        if (!empty($result['link_image'])) {
            return $result['link_image'];
        }

        if (!empty($result['image'])) {
            return $result['image'];
        }

        return '';
    }
}
