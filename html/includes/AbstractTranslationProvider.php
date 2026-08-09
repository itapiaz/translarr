<?php
// html/includes/AbstractTranslationProvider.php
/**
 * Base común para proveedores de traducción.
 * Ofrece helpers HTTP (GET/POST con cURL), saneo de errores y filtros de modelos.
 */
abstract class AbstractTranslationProvider implements TranslationProviderInterface
{
    protected string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * URL base de la API del proveedor (sin barra final).
     */
    abstract protected function baseUrl(): string;

    /**
     * Petición GET con cabeceras y devuelve la respuesta decodificada (array).
     * Lanza Exception con mensaje legible en caso de error (sin exponer la clave).
     */
    protected function get(string $url, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr !== '') {
            throw new Exception('Error de conexión (' . $cerr . ')');
        }
        return $this->decode($resp, $code);
    }

    /**
     * Petición POST JSON con cabeceras base (Authorization, Content-Type).
     */
    protected function postJson(string $url, array $body, array $headers = [], int $timeout = 300): array
    {
        $defaultHeaders = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $defaultHeaders,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($cerr !== '') {
            throw new Exception('Error de conexión (' . $cerr . ')');
        }
        return $this->decode($resp, $code);
    }

    /**
     * Decodifica JSON y lanza errores HTTP legibles.
     */
    protected function decode(string $resp, int $code): array
    {
        $decoded = json_decode($resp, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Respuesta no JSON (HTTP ' . $code . '). ' . substr(strip_tags($resp), 0, 200));
        }
        if ($code >= 400) {
            $msg = $this->extractErrorMessage($decoded)
                ?? 'HTTP ' . $code;
            throw new Exception($msg);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extrae el mensaje de error más útil de una respuesta JSON de error típica.
     */
    protected function extractErrorMessage(?array $decoded): ?string
    {
        if (!$decoded) {
            return null;
        }
        if (!empty($decoded['error']['message']))  return (string) $decoded['error']['message'];
        if (!empty($decoded['error'])) {
            if (is_string($decoded['error'])) return $decoded['error'];
            if (is_array($decoded['error']))  return json_encode($decoded['error']);
        }
        if (!empty($decoded['message']))        return (string) $decoded['message'];
        return null;
    }

    /**
     * Heurística para ocultar modelos que no sirven para subtítulos (embeddings,
     * audio, imagen, vídeo, moderación, TTS, clasificadores, ajuste fino, etc.).
     */
    protected function isBlockedModelId(string $id): bool
    {
        $id = strtolower($id);
        $blocked = [
            'embedding', 'embeddings', 'embed', 'audio', 'tts', 'speech', 'whisper',
            'image', 'dall-e', 'gpt-image', 'video', 'vision', 'moderation',
            'instruct-detection', 'guidance', 'rerank', 'reranker', 'classifier',
            'ocr', 'fim', 'completion-prefix', 'math', 'text-embedding',
            'imagen', 'veo', 'nano', 'robotics', 'realtime', 'live',
        ];
        foreach ($blocked as $b) {
            if (strpos($id, $b) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normaliza un único modelo encontrado en la API.
     */
    protected function normalizeModel(string $id, array $capabilities = [], bool $forceSelectable = false): array
    {
        $blocked = $this->isBlockedModelId($id);
        return [
            'id'            => $id,
            'display_name'  => $id,
            'capabilities'  => implode(',', $capabilities),
            'is_recommended'=> (int) (!$blocked),
            'is_selectable' => (int) (!$blocked || $forceSelectable),
            'raw_data'      => json_encode($capabilities),
        ];
    }
}