<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use Tests\TestCase;

class ApiTest extends TestCase
{
    public function test_missing_or_bad_token_gets_401(): void
    {
        $this->seedCore();
        $this->getJson('/api/v1/me')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer lyn_wrong')->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_expired_token_gets_401(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner, null, null, now()->subDay());
        $this->withHeader('Authorization', 'Bearer ' . $tok)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_me_and_crud_with_full_token(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok];

        $this->withHeaders($h)->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', 'المالك');

        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'عميل API'])
            ->assertCreated()->assertJsonPath('data.name', 'عميل API');
        $c = Client::where('name', 'عميل API')->firstOrFail();

        $this->withHeaders($h)->getJson('/api/v1/clients/' . $c->id)->assertOk()
            ->assertJsonPath('data.id', $c->id);
        $this->withHeaders($h)->deleteJson('/api/v1/clients/' . $c->id)->assertOk();
        $this->assertSoftDeleted('clients', ['id' => $c->id]);
    }

    public function test_scoped_token_is_narrowing_only(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner, 'clients:v, tasks:va');
        $h = ['Authorization' => 'Bearer ' . $tok];

        $this->withHeaders($h)->getJson('/api/v1/clients')->assertOk();
        $this->withHeaders($h)->getJson('/api/v1/projects')->assertForbidden();
        $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'خارج النطاق'])->assertForbidden();
        $this->assertDatabaseMissing('clients', ['name' => 'خارج النطاق']);
    }

    public function test_fields_selection_returns_subset(): void
    {
        $this->seedCore();
        Client::create(['name' => 'مختصر', 'email' => 'a@b.c', 'phone' => '123']);
        $tok = $this->apiToken($this->owner);

        $row = $this->withHeader('Authorization', 'Bearer ' . $tok)
            ->getJson('/api/v1/clients?fields=name,email')->assertOk()->json('data.0');
        $this->assertSame(['id', 'name', 'email'], array_keys($row));
    }

    public function test_idempotency_key_replays_without_duplicate(): void
    {
        $this->seedCore();
        $tok = $this->apiToken($this->owner);
        $h = ['Authorization' => 'Bearer ' . $tok, 'Idempotency-Key' => 'same-key-1'];

        $a = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'مرة واحدة'])->assertCreated();
        $b = $this->withHeaders($h)->postJson('/api/v1/clients', ['name' => 'مرة واحدة'])->assertCreated();

        $b->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Client::where('name', 'مرة واحدة')->count());
    }

    public function test_ip_allowlist_blocks_and_cidr_passes(): void
    {
        $this->seedCore();
        $blocked = $this->apiToken($this->owner, null, '10.9.9.9');
        $this->withHeader('Authorization', 'Bearer ' . $blocked)->getJson('/api/v1/me')->assertForbidden();

        $cidr = $this->apiToken($this->owner, null, '127.0.0.0/8');
        $this->withHeader('Authorization', 'Bearer ' . $cidr)->getJson('/api/v1/me')->assertOk();
    }

    public function test_project_scope_hides_foreign_records(): void
    {
        $this->seedCore();
        // دور بنطاق مشاريع: يرى مشروعه فقط
        $p1 = Project::create(['name' => 'مشروعي', 'status' => 'قيد التنفيذ', 'manager_id' => $this->employee->id]);
        $p2 = Project::create(['name' => 'مشروع غيري', 'status' => 'قيد التنفيذ']);
        $this->employee->role->forceFill(['scope' => 'proj'])->save();

        $tok = $this->apiToken($this->employee);
        $names = collect($this->withHeader('Authorization', 'Bearer ' . $tok)
            ->getJson('/api/v1/projects')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('مشروعي'));
        $this->assertFalse($names->contains('مشروع غيري'));
    }
}
