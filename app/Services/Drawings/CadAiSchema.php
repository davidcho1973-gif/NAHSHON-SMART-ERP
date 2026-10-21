<?php

namespace App\Services\Drawings;

use Illuminate\Validation\ValidationException;

/** A browser geometry snapshot is untrusted data, never instructions or an implicit CAD save. */
class CadAiSchema
{
    public const UNITS = ['drawing', 'mm', 'cm', 'm', 'in', 'ft'];

    public const EDITABLE = ['LINE', 'CIRCLE', 'ARC', 'LWPOLYLINE', 'TEXT', 'MTEXT', 'POINT', 'ERP_DIMENSION'];

    public static function actionDefinitions(): array
    {
        return ['move' => ['entityIds', 'dx', 'dy'], 'rotate' => ['entityIds', 'angle', 'center'], 'scale' => ['entityIds', 'factor', 'center'],
            'style' => ['entityIds', 'color', 'lineWeight'], 'text' => ['entityIds', 'text', 'height', 'width', 'color'], 'delete' => ['entityIds'],
            'add_line' => ['p', 'q', 'layer', 'color'], 'add_rectangle' => ['p', 'q', 'layer', 'color'], 'add_text' => ['p', 'text', 'height', 'width', 'color', 'layer'],
            'add_dimension' => ['p', 'q', 'placement', 'mode', 'height', 'color', 'layer']];
    }

    public static function actionRequired(string $type, array $fields): array
    {
        return $type === 'text' ? ['entityIds'] : array_values(array_diff($fields, ['color', 'lineWeight', 'width']));
    }

    public static function actionJsonSchema(): array
    {
        $point = ['type' => 'object', 'properties' => ['x' => ['type' => 'number', 'minimum' => -1e12, 'maximum' => 1e12], 'y' => ['type' => 'number', 'minimum' => -1e12, 'maximum' => 1e12]], 'required' => ['x', 'y'], 'additionalProperties' => false];
        $choices = [];
        foreach (self::actionDefinitions() as $type => $fields) {
            $properties = ['type' => ['type' => 'string', 'const' => $type]];
            foreach ($fields as $field) {
                $properties[$field] = match ($field) {
                    'entityIds' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 1000, 'uniqueItems' => true, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100]],
                    'p', 'q', 'center', 'placement' => $point,
                    'text' => ['type' => 'string', 'maxLength' => 2000], 'layer' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    'color' => ['type' => 'string', 'pattern' => '^#[a-fA-F0-9]{6}$'], 'lineWeight' => ['type' => 'integer', 'minimum' => -3, 'maximum' => 211],
                    'mode' => ['type' => 'string', 'enum' => ['aligned', 'horizontal', 'vertical']],
                    'factor' => ['type' => 'number', 'minimum' => .000001, 'maximum' => 1000000],
                    'height' => ['type' => 'number', 'minimum' => .000000001, 'maximum' => 1e12], 'width' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1e12],
                    default => ['type' => 'number', 'minimum' => -1e12, 'maximum' => 1e12],
                };
            }
            $choice = ['type' => 'object', 'properties' => $properties, 'required' => ['type', ...self::actionRequired($type, $fields)], 'additionalProperties' => false];
            if (in_array($type, ['style', 'text'], true)) {
                $choice['anyOf'] = array_map(fn ($field) => ['required' => [$field]], $type === 'style' ? ['color', 'lineWeight'] : ['text', 'height', 'width', 'color']);
            }
            $choices[] = $choice;
        }

        return ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['oneOf' => $choices]];
    }

    public function fail(string $message = 'Invalid CAD context or action schema.'): never
    {
        throw ValidationException::withMessages(['cad' => $message]);
    }

    public function keys(array $value, array $allowed, array $required = []): void
    {
        if (array_diff(array_keys($value), $allowed) || array_diff($required, array_keys($value))) {
            $this->fail();
        }
    }

    private function number(mixed $value, float $min = -1e12, float $max = 1e12): void
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || $value < $min || $value > $max) {
            $this->fail();
        }
    }

    private function text(mixed $value, int $max = 100, bool $empty = false): void
    {
        if (! is_string($value) || mb_strlen($value) > $max || (! $empty && trim($value) === '') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $value)) {
            $this->fail();
        }
    }

    public function hash(mixed $value): void
    {
        if (! is_string($value) || ! preg_match('/^[a-f0-9]{64}$/D', $value)) {
            $this->fail('A SHA256 documentHash of the complete current browser document is required.');
        }
    }

    private function point(mixed $value, bool $bulge = false): void
    {
        if (! is_array($value)) {
            $this->fail();
        }
        $this->keys($value, $bulge ? ['x', 'y', 'bulge'] : ['x', 'y'], ['x', 'y']);
        $this->number($value['x']);
        $this->number($value['y']);
        if (array_key_exists('bulge', $value)) {
            $this->number($value['bulge']);
        }
    }

    private function color(mixed $value, bool $nullable = false): void
    {
        if ($nullable && $value === null) {
            return;
        }
        if (! is_string($value) || ! preg_match('/^#[a-fA-F0-9]{6}$/D', $value)) {
            $this->fail();
        }
    }

    private function geometry(string $type, mixed $geometry): void
    {
        if (! in_array($type, self::EDITABLE, true)) {
            if ($geometry !== null) {
                $this->fail();
            }

return;
        }
        if (! is_array($geometry)) {
            $this->fail();
        }
        $keys = match ($type) {
            'LINE' => ['p', 'q'], 'POINT' => ['p'], 'CIRCLE' => ['center', 'radius'], 'ARC' => ['center', 'radius', 'start', 'end'],
            'LWPOLYLINE' => ['points', 'closed'], 'TEXT', 'MTEXT' => ['p', 'text', 'height', 'width', 'angle'],
            'ERP_DIMENSION' => ['p', 'q', 'placement', 'mode', 'axis', 'textPosition', 'height'],
        };
        $required = array_diff($keys, ['axis', 'textPosition']);
        $this->keys($geometry, $keys, $required);
        foreach ($required as $field) {
            if ($geometry[$field] === null) {
                $this->fail();
            }
        }
        foreach (['p', 'q', 'center', 'placement', 'axis', 'textPosition'] as $field) {
            if (isset($geometry[$field])) {
                $this->point($geometry[$field]);
            }
        }
        if (array_key_exists('radius', $geometry)) {
            $this->number($geometry['radius'], 0);
        }
        if (array_key_exists('height', $geometry)) {
            $this->number($geometry['height'], 0.000000001);
        }
        if (isset($geometry['width'])) {
            $this->number($geometry['width'], 0);
        }
        foreach (['angle', 'start', 'end'] as $field) {
            if (isset($geometry[$field])) {
                $this->number($geometry[$field], -360000, 360000);
            }
        }
        if (isset($geometry['text'])) {
            $this->text($geometry['text'], 2000, true);
        }
        if (isset($geometry['mode']) && ! in_array($geometry['mode'], ['aligned', 'horizontal', 'vertical'], true)) {
            $this->fail();
        }
        if (isset($geometry['axis']) && abs(hypot($geometry['axis']['x'], $geometry['axis']['y']) - 1) > 1e-8) {
            $this->fail();
        }
        if (isset($geometry['points'])) {
            if (! is_array($geometry['points']) || ! array_is_list($geometry['points']) || count($geometry['points']) > 2000 || count($geometry['points']) < 2 || ! is_bool($geometry['closed'])) {
                $this->fail();
            }
            foreach ($geometry['points'] as $point) {
                $this->point($point, true);
            }
        }
    }

    public function context(array $context): array
    {
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 524288) {
            $this->fail('Shared CAD context exceeds 512 KiB. Share a smaller selection.');
        }
        $this->keys($context, ['version', 'documentHash', 'unit', 'scope', 'selection', 'entities', 'layers', 'totals'], ['version', 'documentHash', 'unit', 'scope', 'selection', 'entities', 'layers', 'totals']);
        if ($context['version'] !== 1 || ! in_array($context['unit'], self::UNITS, true) || ! in_array($context['scope'], ['selection', 'visible'], true)) {
            $this->fail();
        }
        $this->hash($context['documentHash']);
        if (! is_array($context['entities']) || ! array_is_list($context['entities']) || count($context['entities']) > 5000 || ! is_array($context['layers']) || ! array_is_list($context['layers']) || count($context['layers']) > 200 || ! is_array($context['selection']) || ! array_is_list($context['selection']) || count($context['selection']) > 5000) {
            $this->fail('Share at most 5000 CAD entities and 200 layers. Use a selection for large drawings.');
        }
        $layers = [];
        foreach ($context['layers'] as $layer) {
            if (! is_array($layer)) {
                $this->fail();
            }
            $this->keys($layer, ['name', 'color', 'visible', 'locked'], ['name', 'color', 'visible', 'locked']);
            $this->text($layer['name']);
            $this->color($layer['color'], true);
            if (! is_bool($layer['visible']) || ! is_bool($layer['locked']) || isset($layers[$layer['name']])) {
                $this->fail();
            }
            $layers[$layer['name']] = $layer;
        }
        $ids = [];
        foreach ($context['entities'] as $entity) {
            if (! is_array($entity)) {
                $this->fail();
            }
            $this->keys($entity, ['id', 'type', 'layer', 'editable', 'geometry', 'style', 'quantities'], ['id', 'type', 'layer', 'editable', 'geometry', 'style', 'quantities']);
            $this->text($entity['id']);
            $this->text($entity['type'], 40);
            $this->text($entity['layer']);
            if (isset($ids[$entity['id']]) || ! isset($layers[$entity['layer']]) || ! $layers[$entity['layer']]['visible'] || ! is_bool($entity['editable'])) {
                $this->fail();
            }
            $ids[$entity['id']] = true;
            if (! is_array($entity['style']) || ! is_array($entity['quantities'])) {
                $this->fail();
            }
            if (! ($entity['geometry'] === null && ! $entity['editable'] && ($entity['quantities']['available'] ?? null) === false)) {
                $this->geometry($entity['type'], $entity['geometry']);
            }
            $this->keys($entity['style'], ['color', 'lineType', 'lineWeight'], ['color', 'lineType', 'lineWeight']);
            $this->color($entity['style']['color'], true);
            $this->text($entity['style']['lineType']);
            if (! is_int($entity['style']['lineWeight']) || $entity['style']['lineWeight'] < -3 || $entity['style']['lineWeight'] > 211) {
                $this->fail();
            }
            $this->keys($entity['quantities'], ['length', 'area', 'available', 'reason'], ['length', 'area', 'available', 'reason']);
            if (! is_bool($entity['quantities']['available'])) {
                $this->fail();
            }
            foreach (['length', 'area'] as $field) {
                if ($entity['quantities'][$field] !== null) {
                    $this->number($entity['quantities'][$field], 0, 1e30);
                }
            }
            if ($entity['quantities']['reason'] !== null) {
                $this->text($entity['quantities']['reason'], 500, true);
            }
        }
        foreach ($context['selection'] as $id) {
            if (! is_string($id) || ! isset($ids[$id])) {
                $this->fail();
            }
        }
        if (count(array_unique($context['selection'])) !== count($context['selection'])) {
            $this->fail();
        }
        if (! is_array($context['totals'])) {
            $this->fail();
        }
        $this->keys($context['totals'], ['length', 'area', 'unavailableCount'], ['length', 'area', 'unavailableCount']);
        $this->number($context['totals']['length'], 0, 1e30);
        $this->number($context['totals']['area'], 0, 1e30);
        if (! is_int($context['totals']['unavailableCount']) || $context['totals']['unavailableCount'] < 0 || $context['totals']['unavailableCount'] > count($ids)) {
            $this->fail();
        }

        return $context;
    }

    public function actions(array $actions, array $context): array
    {
        if (! array_is_list($actions) || count($actions) < 1 || count($actions) > 100 || strlen(json_encode($actions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 131072) {
            $this->fail('Use 1–100 CAD actions within 128 KiB.');
        }
        $entities = array_column($context['entities'], null, 'id');
        $layers = array_column($context['layers'], null, 'name');
        $targets = 0;
        foreach ($actions as $action) {
            if (! is_array($action) || ! is_string($action['type'] ?? null)) {
                $this->fail();
            }
            $fields = self::actionDefinitions()[$action['type']] ?? $this->fail('Unsupported CAD action type.');
            $required = self::actionRequired($action['type'], $fields);
            $this->keys($action, ['type', ...$fields], ['type', ...$required]);
            if (in_array(null, $action, true)) {
                $this->fail();
            }
            if (isset($action['entityIds'])) {
                if (! is_array($action['entityIds']) || ! array_is_list($action['entityIds']) || count($action['entityIds']) < 1 || count(array_unique($action['entityIds'], SORT_REGULAR)) !== count($action['entityIds'])) {
                    $this->fail();
                }
                $targets += count($action['entityIds']);
                foreach ($action['entityIds'] as $id) {
                    if (! is_string($id) || ! isset($entities[$id])) {
                        $this->fail('Only explicitly shared CAD entity IDs may be edited.');
                    }
                    $entity = $entities[$id];
                    $layer = $layers[$entity['layer']];
                    if (! $entity['editable'] || ! in_array($entity['type'], self::EDITABLE, true) || ! $layer['visible'] || $layer['locked']) {
                        $this->fail('Locked, hidden or unsupported CAD entities cannot be edited.');
                    }
                    if ($action['type'] === 'text' && ! in_array($entity['type'], ['TEXT', 'MTEXT'], true)) {
                        $this->fail();
                    }
                }
                if ($action['type'] === 'style' && ! isset($action['color']) && ! isset($action['lineWeight'])) {
                    $this->fail();
                }
                if ($action['type'] === 'text' && ! array_intersect(['text', 'height', 'width', 'color'], array_keys($action))) {
                    $this->fail();
                }
            } else {
                $targets++;
                if (! is_string($action['layer'] ?? null) || ! isset($layers[$action['layer']]) || ! $layers[$action['layer']]['visible'] || $layers[$action['layer']]['locked']) {
                    $this->fail('New entities require a shared unlocked visible layer.');
                }
            }
            if ($targets > 1000) {
                $this->fail('At most 1000 CAD targets may be changed in one command.');
            }
            foreach (['p', 'q', 'center', 'placement'] as $field) {
                if (isset($action[$field])) {
                    $this->point($action[$field]);
                }
            }
            foreach (['dx', 'dy', 'angle'] as $field) {
                if (isset($action[$field])) {
                    $this->number($action[$field]);
                }
            }
            if (isset($action['factor'])) {
                $this->number($action['factor'], 0.000001, 1000000);
            }
            if (isset($action['height'])) {
                $this->number($action['height'], 0.000000001);
            }
            if (isset($action['width'])) {
                $this->number($action['width'], 0);
            }
            if (isset($action['text'])) {
                $this->text($action['text'], 2000, true);
            }
            if (isset($action['color'])) {
                $this->color($action['color']);
            }
            if (isset($action['mode']) && ! in_array($action['mode'], ['aligned', 'horizontal', 'vertical'], true)) {
                $this->fail();
            }
            if (isset($action['lineWeight']) && (! is_int($action['lineWeight']) || $action['lineWeight'] < -3 || $action['lineWeight'] > 211)) {
                $this->fail();
            }
        }

        return $actions;
    }
}
