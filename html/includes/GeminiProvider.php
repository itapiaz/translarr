<?php
// html/includes/GeminiProvider.php
/**
 * Proveedor de traducción Google Gemini (Generative Language API).
 */
class GeminiProvider extends AbstractTranslationProvider
{
    public function getKey(): string
    {
        return 'gemini';
    }

    public function getLabel(): string
    {
        return 'Google Gemini';
    }

    protected function baseUrl(): string
    {
        return 'https://generativelanguage.googleapis.com/v1beta';
    }

    private function keyQuery(): string
    {
        return '?key=' . urlencode($this->apiKey);
    }

    public function listModels(): array
    {
        $data = $this->get($this->baseUrl() . '/models' . $this->keyQuery());
        $models = [];
        foreach ($data['models'] ?? [] as $m) {
            $id = (string) ($m['name'] ?? '');
            // El nombre llega como "models/gemini-2.5-flash"
            if (strpos($id, 'models/') === 0) {
                $id = substr($id, strlen('models/'));
            }
            if ($id === '') {
                continue;
            }
            $methods = $m['supportedGenerationMethods'] ?? [];
            // Solo modelos que admiten generateContent (texto/chat)
            if (!in_array('generateContent', $methods, true)) {
                continue;
            }
            $models[] = $this->normalizeModel($id, $methods);
        }
        return $models;
    }

    public function translate(string $model, string $systemPrompt, string $subtitleChunk): array
    {
        $body = [
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => [['role' => 'user', 'parts' => [['text' => $subtitleChunk]]]],
            'generationConfig'   => ['temperature' => 0.3],
        ];
        $url = $this->baseUrl() . '/models/' . urlencode($model) . ':generateContent' . $this->keyQuery();
        $data = $this->postJson($url, $body);

        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $p) {
            $text .= ($p['text'] ?? '');
        }
        if (trim($text) === '') {
            $blockReason = $data['candidates'][0]['finishReason'] ?? 'unknown';
            throw new Exception('Respuesta vacía del modelo (finishReason: ' . $blockReason . ').');
        }
        $usage = $data['usageMetadata'] ?? [];
        return [
            'content'       => $text,
            'input_tokens'  => isset($usage['promptTokenCount'])     ? (int) $usage['promptTokenCount']     : null,
            'output_tokens' => isset($usage['candidatesTokenCount']) ? (int) $usage['candidatesTokenCount'] : null,
            'raw_model'     => $data['modelVersion'] ?? $model,
        ];
    }

    public function test(string $model): array
    {
        try {
            $out = $this->translate($model, 'You are a test.', 'Return exactly: OK');
            $ok = (trim($out['content']) === 'OK');
            return [
                'ok'        => $ok,
                'message'   => $ok ? $model . ' responde correctamente.' : 'Respuesta inesperada.',
                'raw_model' => $model,
            ];
        } catch (Exception $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'raw_model' => $model];
        }
    }
}