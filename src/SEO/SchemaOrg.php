<?php
namespace Rhapsody\Core\SEO;

class SchemaOrg
{
    protected array $schemas = [];

    /**
     * Add a structured context schema type.
     * * @param string $type The Schema.org type (e.g., 'Article', 'Product', 'BreadcrumbList')
     * @param array $data The properties associated with that type
     * @return self
     */
    public function add(string $type, array $data): self
    {
        $this->schemas[] = array_merge([
            '@context' => 'https://schema.org',
            '@type'    => $type,
        ], $data);

        return $this;
    }

    /**
     * Render all added schemas into safe script block tags.
     *
     * @return string
     */
    public function render(): string
    {
        if (empty($this->schemas)) {
            return '';
        }

        $html = '';
        foreach ($this->schemas as $schema) {
            $html .= '<script type="application/ld+json">' .
            json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) .
                "</script>\n";
        }

        return $html;
    }
}
