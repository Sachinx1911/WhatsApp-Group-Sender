<?php

namespace App\Livewire\Layout;

use App\Enums\SendStatus;
use App\Models\Campaign;
use App\Models\CampaignGroup;
use App\Models\Group;
use App\Models\MessageTemplate;
use App\Support\WhatsAppFormatter;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Header search across groups, campaigns (title and message), templates and
 * failure reasons (docs/MASTER_PROMPT.md §42). Each section links to the full,
 * filtered list on its own screen.
 */
class GlobalSearch extends Component
{
    public const PER_SECTION = 5;

    public string $query = '';

    /** @return array<int, array{label: string, icon: string, all_url: string, items: array<int, array{title: string, meta: string, url: string}>}> */
    #[Computed]
    public function sections(): array
    {
        $term = trim($this->query);

        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = "%{$term}%";

        return array_values(array_filter([
            $this->section('Groups', 'users', route('groups.index', ['search' => $term]),
                Group::with('category')->search($term)->orderBy('name')->limit(self::PER_SECTION)->get()
                    ->map(fn (Group $g) => [
                        'title' => $g->name,
                        'meta' => $g->category->name.($g->member_count ? " · {$g->member_count} members" : ''),
                        'url' => route('groups.index', ['search' => $g->name]),
                    ])),

            $this->section('Campaigns', 'send', route('history.index', ['search' => $term]),
                Campaign::query()
                    ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('message', 'like', $like))
                    ->latest()->limit(self::PER_SECTION)->get()
                    ->map(fn (Campaign $c) => [
                        'title' => $c->title,
                        'meta' => $c->status->label().' · '.$c->created_at->format('d M Y'),
                        'url' => route('history.index', ['search' => $c->title]),
                    ])),

            $this->section('Templates', 'file-text', route('templates.index', ['search' => $term]),
                MessageTemplate::query()
                    ->where(fn (Builder $q) => $q->where('title', 'like', $like)->orWhere('message', 'like', $like))
                    ->orderBy('title')->limit(self::PER_SECTION)->get()
                    ->map(fn (MessageTemplate $t) => [
                        'title' => $t->title,
                        'meta' => WhatsAppFormatter::plain($t->message, 60),
                        'url' => route('templates.index', ['search' => $t->title]),
                    ])),

            $this->section('Failed messages', 'triangle-alert', route('failed.index', ['search' => $term]),
                CampaignGroup::query()
                    ->where('status', SendStatus::Failed)
                    ->where(fn (Builder $q) => $q->where('error_message', 'like', $like)->orWhere('group_name', 'like', $like))
                    ->latest('updated_at')->limit(self::PER_SECTION)->get()
                    ->map(fn (CampaignGroup $f) => [
                        'title' => $f->group_name,
                        'meta' => $f->error_type?->label() ?? 'Failed',
                        'url' => route('failed.index', ['search' => $f->group_name]),
                    ])),
        ]));
    }

    private function section(string $label, string $icon, string $allUrl, $items): ?array
    {
        return $items->isEmpty() ? null : [
            'label' => $label,
            'icon' => $icon,
            'all_url' => $allUrl,
            'items' => $items->all(),
        ];
    }

    public function render()
    {
        return view('livewire.layout.global-search');
    }
}
