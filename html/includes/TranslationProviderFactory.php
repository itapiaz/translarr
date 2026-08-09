<?php
// html/includes/TranslationProviderFactory.php
/**
 * Fábrica de proveedores de traducción.
 * Carga las implementaciones disponibles y crea la instancia según la clave.
 */
class TranslationProviderFactory
{
    /**
     * Registro de clases de proveedor (clave => clase).
     */
    private const PROVIDERS = [
        'deepseek' => DeepSeekProvider::class,
        'gemini'   => GeminiProvider::class,
        'openai'   => OpenAIProvider::class,
        'mistral'  => MistralProvider::class,
    ];

    /**
     * Carga las definiciones de clase de todos los proveedores una sola vez.
     */
    public static function loadProviders(): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;
        require_once __DIR__ . '/TranslationProviderInterface.php';
        require_once __DIR__ . '/AbstractTranslationProvider.php';
        foreach (self::PROVIDERS as $class) {
            $file = __DIR__ . '/' . $class . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }

    /**
     * Lista de proveedores disponibles: clave => etiqueta.
     */
    public static function availableProviders(): array
    {
        self::loadProviders();
        // Instanciación con clave vacía solo para leer la etiqueta.
        $out = [];
        foreach (self::PROVIDERS as $key => $class) {
            if (class_exists($class)) {
                $out[$key] = (new $class(''))->getLabel();
            }
        }
        return $out;
    }

    /**
     * Crea una instancia del proveedor solicitado.
     */
    public static function create(string $key, string $apiKey): ?TranslationProviderInterface
    {
        self::loadProviders();
        $key = strtolower(trim($key));
        if (!isset(self::PROVIDERS[$key])) {
            return null;
        }
        $class = self::PROVIDERS[$key];
        if (!class_exists($class)) {
            return null;
        }
        return new $class($apiKey);
    }
}