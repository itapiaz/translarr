<?php
// html/includes/MistralProvider.php
/**
 * Proveedor de traducción Mistral AI (Chat Completions API).
 */
class MistralProvider extends AbstractTranslationProvider
{
    public function getKey(): string
    {
        return 'mistral';
    }

    public function getLabel(): string
    {
        return 'Mistral AI';
    }

    protected function baseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }

    private function authHeader(): array
    {
        return ['Authorization: Bearer ' . $this->apiKey];
    }

    public function listModels(): array
    {
        $data = $this->get($this->baseUrl() . '/models', $this->authHeader());
        $models = [];
        foreach ($data['data'] ?? [] as $m) {
            $id = (string) ($m['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $models[] = $this->normalizeModel($id);
        }
        return $models;
    }

    public function translate(string $model, string $systemPrompt, string $subtitleChunk): array
    {
        $body = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $subtitleChunk],
            ],
            'temperature' => 0.3,
        ];
        $data = $this->postJson($this->baseUrl() . '/chat/completions', $body, $this->authHeader());

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === null || $content === '') {
            throw new Exception('Respuesta vacía del modelo.');
        }
        $usage = $data['usage'] ?? [];
        return [
            'content'       => (string) $content,
            'input_tokens'  => isset($usage['prompt_tokens'])     ? (int) $usage['prompt_tokens']     : null,
            'output_tokens' => isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            'raw_model'     => $data['model'] ?? $model,
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