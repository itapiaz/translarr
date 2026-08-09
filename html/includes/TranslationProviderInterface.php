<?php
// html/includes/TranslationProviderInterface.php
/**
 * Contrato común para proveedores de traducción de IA.
 *
 * Cada proveedor expone:
 *  - su clave de registro (deepseek, gemini, openai, mistral)
 *  - el listado dinámico de modelos disponibles desde su API
 *  - una traducción de un bloque de subtítulos
 *  - una prueba funcional mínima de un modelo
 */
interface TranslationProviderInterface
{
    /**
     * Clave de registro del proveedor (ej: 'deepseek', 'gemini').
     */
    public function getKey(): string;

    /**
     * Nombre legible del proveedor (ej: 'DeepSeek').
     */
    public function getLabel(): string;

    /**
     * Consulta la API y devuelve los modelos disponibles en formato normalizado.
     *
     * @return array<int, array{
     *   id: string,
     *   display_name: string,
     *   capabilities: string,
     *   is_recommended: int,
     *   is_selectable: int,
     *   raw_data: string
     * }>
     */
    public function listModels(): array;

    /**
     * Traduce un bloque de subtítulos y devuelve el contenido junto con el uso.
     *
     * @return array{content: string, input_tokens: ?int, output_tokens: ?int, raw_model: ?string}
     */
    public function translate(string $model, string $systemPrompt, string $subtitleChunk): array;

    /**
     * Prueba funcional mínima de un modelo (debe devolver exactamente "OK").
     *
     * @return array{ok: bool, message: string, raw_model: ?string}
     */
    public function test(string $model): array;
}