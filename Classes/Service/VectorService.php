<?php
declare(strict_types=1);

namespace PITS\AiSemanticSearch\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2025 Developer <contact@pitsolutions.com>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class VectorService implements SingletonInterface
{
    private array $config;

    public function __construct()
    {
        $this->config = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('ai_semantic_search');
    }

    public function generateEmbedding(string $text): array
    {
        $apiKey = $this->config['openai_api_key'] ?? '';
        if (empty($apiKey)) {
            throw new \Exception('OpenAI API key not configured');
        }

        // Clean and prepare text
        $text = $this->preprocessText($text);

        // Check if text needs chunking
        if (strlen($text) > 32000) {
            // Generate embeddings for chunks and return average
            $chunkEmbeddings = $this->generateEmbeddingsForChunks($text);
            $embeddings = array_column($chunkEmbeddings, 'embedding');
            return $this->calculateAverageEmbedding($embeddings);
        }

        // Generate single embedding for text that fits in one chunk
        return $this->generateSingleEmbedding($text);
    }

    /**
     * Generate embedding for a single text chunk
     */
    private function generateSingleEmbedding(string $text): array
    {
        $apiKey = $this->config['openai_api_key'] ?? '';
        
        $data = [
            'model' => $this->config['embedding_model'] ?? 'text-embedding-3-small',
            'input' => $text,
            'encoding_format' => 'float'
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $apiKey
                ],
                'content' => json_encode($data)
            ]
        ]);

        $response = file_get_contents('https://api.openai.com/v1/embeddings', false, $context);
        
        if ($response === false) {
            throw new \Exception('Failed to generate embedding');
        }

        $result = json_decode($response, true);
        
        if (isset($result['error'])) {
            throw new \Exception('OpenAI API Error: ' . $result['error']['message']);
        }

        $embedding = $result['data'][0]['embedding'];
        
        // Normalize the vector (even though OpenAI embeddings are already normalized)
        return $this->normalizeVector($embedding);
    }

    /**
     * Generate embeddings for multiple text chunks
     */
    public function generateEmbeddingsForChunks(string $text): array
    {
        $chunks = $this->createTextChunks($text);
        $embeddings = [];
        
        foreach ($chunks as $index => $chunk) {
            try {
                $embeddings[] = [
                    'chunk_index' => $index,
                    'text' => $chunk,
                    'embedding' => $this->generateSingleEmbedding($chunk)
                ];
            } catch (\Exception $e) {
                // Log error but continue with other chunks
                error_log("Failed to generate embedding for chunk {$index}: " . $e->getMessage());
            }
        }
        
        return $embeddings;
    }

    /**
     * Create text chunks with overlap for better context preservation
     */
    private function createTextChunks(string $text, int $maxChunkSize = 6000, int $overlapSize = 500): array
    {
        $text = $this->preprocessText($text);
        
        // If text is shorter than max chunk size, return as single chunk
        if (strlen($text) <= $maxChunkSize) {
            return [$text];
        }
        
        $chunks = [];
        $sentences = $this->splitIntoSentences($text);
        
        $currentChunk = '';
        $currentSize = 0;
        $overlapBuffer = '';
        
        foreach ($sentences as $sentence) {
            $sentenceLength = strlen($sentence);
            
            // If adding this sentence would exceed the chunk size
            if ($currentSize + $sentenceLength > $maxChunkSize && !empty($currentChunk)) {
                // Add the current chunk
                $chunks[] = trim($currentChunk);
                
                // Start new chunk with overlap from previous chunk
                $currentChunk = $overlapBuffer . $sentence;
                $currentSize = strlen($currentChunk);
                
                // Update overlap buffer with the end of current chunk
                $overlapBuffer = $this->getOverlapText($currentChunk, $overlapSize);
            } else {
                // Add sentence to current chunk
                $currentChunk .= ($currentChunk ? ' ' : '') . $sentence;
                $currentSize += $sentenceLength + ($currentChunk ? 1 : 0); // +1 for space
                
                // Update overlap buffer
                if ($currentSize > $overlapSize) {
                    $overlapBuffer = $this->getOverlapText($currentChunk, $overlapSize);
                }
            }
        }
        
        // Add the last chunk if it has content
        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }

    /**
     * Split text into sentences for better chunking
     */
    private function splitIntoSentences(string $text): array
    {
        // Split on sentence endings, but be careful with abbreviations
        $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $text, -1, PREG_SPLIT_NO_EMPTY);
        
        // If no sentences found (no proper sentence structure), split by paragraphs or newlines
        if (count($sentences) <= 1) {
            $sentences = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY);
        }
        
        // If still no good split, split by approximate word count
        if (count($sentences) <= 1) {
            $words = explode(' ', $text);
            $sentences = [];
            $wordsPerSentence = 50; // Approximate words per sentence
            
            for ($i = 0; $i < count($words); $i += $wordsPerSentence) {
                $sentenceWords = array_slice($words, $i, $wordsPerSentence);
                $sentences[] = implode(' ', $sentenceWords);
            }
        }
        
        return $sentences;
    }

    /**
     * Get overlap text from the end of a chunk
     */
    private function getOverlapText(string $text, int $overlapSize): string
    {
        if (strlen($text) <= $overlapSize) {
            return $text;
        }
        
        $overlapText = substr($text, -$overlapSize);
        
        // Try to start at a word boundary
        $spacePos = strpos($overlapText, ' ');
        if ($spacePos !== false && $spacePos < $overlapSize / 2) {
            $overlapText = substr($overlapText, $spacePos + 1);
        }
        
        return $overlapText;
    }

    private function preprocessText(string $text): string
    {
        // Remove HTML tags
        $text = strip_tags($text);
        
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Trim
        $text = trim($text);
        
        // Remove any control characters
        $text = preg_replace('/[\x00-\x1F\x7F]/', '', $text);
        
        return $text;
    }

    private function normalizeVector(array $vector): array
    {
        // Calculate L2 norm
        $norm = sqrt(array_sum(array_map(function($x) { return $x * $x; }, $vector)));
        
        // Avoid division by zero
        if ($norm == 0) {
            return $vector;
        }
        
        // Normalize each component
        return array_map(function($x) use ($norm) { return $x / $norm; }, $vector);
    }

    public function calculateCosineSimilarity(array $vector1, array $vector2): float
    {
        // Ensure both vectors are normalized
        $vector1 = $this->normalizeVector($vector1);
        $vector2 = $this->normalizeVector($vector2);
        
        // Calculate dot product (cosine similarity for normalized vectors)
        $dotProduct = 0;
        for ($i = 0; $i < count($vector1); $i++) {
            $dotProduct += $vector1[$i] * $vector2[$i];
        }
        
        return $dotProduct;
    }

    /**
     * Calculate average embedding from multiple chunk embeddings
     */
    public function calculateAverageEmbedding(array $embeddings): array
    {
        if (empty($embeddings)) {
            throw new \Exception('No embeddings provided for averaging');
        }
        
        $dimension = count($embeddings[0]);
        $averageVector = array_fill(0, $dimension, 0);
        
        // Sum all embeddings
        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dimension; $i++) {
                $averageVector[$i] += $embedding[$i];
            }
        }
        
        // Divide by count to get average
        $count = count($embeddings);
        for ($i = 0; $i < $dimension; $i++) {
            $averageVector[$i] /= $count;
        }
        
        return $this->normalizeVector($averageVector);
    }

    /**
     * Find the best matching chunk from multiple embeddings
     */
    public function findBestMatchingChunk(array $chunkEmbeddings, array $queryEmbedding): array
    {
        $bestMatch = null;
        $bestSimilarity = -1;
        
        foreach ($chunkEmbeddings as $chunkData) {
            $similarity = $this->calculateCosineSimilarity($chunkData['embedding'], $queryEmbedding);
            
            if ($similarity > $bestSimilarity) {
                $bestSimilarity = $similarity;
                $bestMatch = $chunkData;
                $bestMatch['similarity'] = $similarity;
            }
        }
        
        return $bestMatch;
    }

    public function getEmbeddingDimension(): int
    {
        $model = $this->config['embedding_model'] ?? 'text-embedding-3-small';
        
        return match($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536
        };
    }

    public function formatVectorForPostgreSQL(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }
}