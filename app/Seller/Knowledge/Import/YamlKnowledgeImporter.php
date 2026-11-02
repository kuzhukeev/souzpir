<?php

namespace App\Seller\Knowledge\Import;

use App\Seller\Knowledge\Contracts\KnowledgeRepository;
use App\Seller\Knowledge\KnowledgeSnapshot;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

final class YamlKnowledgeImporter
{
    public const FILES = ['conditions', 'facts', 'triggers', 'guard_rules', 'policy', 'intents', 'escalation', 'persona', 'fewshot', 'closed_topics'];

    public function __construct(private readonly KnowledgeRepository $knowledge) {}

    /** Parse before writing; explicit widget import replaces the current draft in one transaction. */
    public function importAndPublish(TenantContext $context, string $directory, bool $widgetGreeting = false): KnowledgeSnapshot
    {
        return $this->publishData($context, $this->read($directory), $widgetGreeting);
    }

    public function publishData(TenantContext $context, array $data, bool $widgetGreeting = false): KnowledgeSnapshot
    {
        if ($widgetGreeting && ! collect($data['fewshot']['examples'] ?? [])->contains(fn ($example) => ($example['tag'] ?? null) === 'open_greeting_widget')) {
            $data['fewshot']['examples'][] = ['tag' => 'open_greeting_widget', 'client_type' => 'all', 'text' => 'Здравствуйте! Чем я могу вам помочь?', 'source' => 'widget', 'confidence' => 0.9];
        }

        // Repository validates structure and authorization; nested DB transactions are intentional:
        // a failed import never leaves a partially imported draft or a changed active pointer.
        return DB::transaction(function () use ($context, $data, $widgetGreeting): KnowledgeSnapshot {
            $existing = DB::table('knowledge_releases')->where('organization_id', $context->organizationId)->where('brand_id', $context->brandId)->where('status', 'draft')->first(['id', 'version']);
            if ($existing && ! $widgetGreeting) {
                abort(409, 'Черновик уже существует.');
            }
            if ($existing) {
                $version = (int) $existing->version;
                $editor = $this->knowledge->draftEditor($context, $existing->id);
                foreach ($editor['groups'] as $group) {
                    foreach ($group['facts'] as $fact) {
                        $version = $this->knowledge->deleteFact($context, $existing->id, $fact['id'], $version);
                    }
                }
                $withoutFacts = $data;
                foreach ($withoutFacts['facts'] as &$group) {
                    $group['facts'] = [];
                }
                unset($group);
                $draft = $this->knowledge->saveDraft($context, $existing->id, $version, $withoutFacts);
                $version = $draft['version'];
                foreach ($data['facts'] as $group) {
                    foreach ($group['facts'] as $fact) {
                        $version = $this->knowledge->addFact($context, $draft['id'], $version, $group['id'], $fact, 'import', 'active')['version'];
                    }
                }
                $draft['version'] = $version;
            } else {
                $draft = $this->knowledge->createDraft($context, $data);
            }
            $version = $draft['version'];
            $editor = $this->knowledge->draftEditor($context, $draft['id']);
            foreach ($editor['groups'] as $group) {
                foreach ($group['facts'] as $fact) {
                    $version = $this->knowledge->setFact($context, $draft['id'], $fact['id'], $version, $fact['text'], 'active', 'import');
                }
            }

            return $this->knowledge->publish($context, $draft['id'], $version);
        });
    }

    /** Export in memory only; the caller chooses how to store the resulting files. */
    public function export(TenantContext $context, string $releaseId): array
    {
        $snapshot = $this->knowledge->forRelease($context, $releaseId);
        $files = [];
        foreach (self::FILES as $file) {
            $files[$file.'.yaml'] = Yaml::dump($snapshot->all($file), 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        }

        return $files;
    }

    private function read(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new InvalidArgumentException('Knowledge directory does not exist.');
        }
        $data = [];
        foreach (self::FILES as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$file.'.yaml';
            if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
                throw new InvalidArgumentException('Missing or unsafe knowledge file: '.$file);
            }
            $size = filesize($path);
            if ($size === false || $size > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('Knowledge file is too large: '.$file);
            }
            $content = file_get_contents($path);
            if ($content === false) {
                throw new InvalidArgumentException('Cannot read knowledge file: '.$file);
            }
            $parsed = Yaml::parse($content, Yaml::PARSE_EXCEPTION_ON_ALIAS);
            if (! is_array($parsed)) {
                throw new InvalidArgumentException('Knowledge namespace must be an array: '.$file);
            }
            $data[$file] = $parsed;
        }

        return $data;
    }
}
