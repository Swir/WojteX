<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires full PDO SQLite and the Laravel HTTP test kernel. */
class CategoryHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('c', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
        $admin = new User();
        $admin->forceFill([
            'name' => 'Category owner', 'email' => 'category-admin@example.test',
            'password' => 'NotARealProductionPassword2026!', 'role' => 'admin',
            'color' => 'violet', 'is_banned' => false,
        ])->save();
        $this->actingAs($admin);
    }

    private function category(string $name, ?int $parentId = null): int
    {
        return DB::table('categories')->insertGetId([
            'name' => $name, 'slug' => strtolower($name), 'description' => '',
            'parent_id' => $parentId, 'is_private' => false,
            'sort_order' => 0, 'color' => 'violet', 'icon' => 'folder',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_numeric_string_parent_cannot_point_to_itself(): void
    {
        $id = $this->category('Root');
        // A legitimate HTML form may submit a numeric string, not an integer.
        $this->patchJson('/api/admin/categories/'.$id, ['parent_id' => (string) $id])
            ->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $id, 'parent_id' => null]);
    }

    public function test_indirect_cycle_is_rejected_even_with_string_ancestors(): void
    {
        $root = $this->category('Root');
        $child = $this->category('Child', $root);
        $grandchild = $this->category('Grandchild', $child);
        $this->patchJson('/api/admin/categories/'.$root, ['parent_id' => (string) $grandchild])
            ->assertUnprocessable();
        $this->assertDatabaseHas('categories', ['id' => $root, 'parent_id' => null]);
        $this->assertDatabaseHas('categories', ['id' => $grandchild, 'parent_id' => $child]);
    }

    public function test_valid_numeric_string_parent_and_explicit_detach_work(): void
    {
        $root = $this->category('Root');
        $child = $this->category('Child');
        $this->patchJson('/api/admin/categories/'.$child, ['parent_id' => (string) $root])
            ->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $child, 'parent_id' => $root]);
        $this->patchJson('/api/admin/categories/'.$child, ['parent_id' => null])
            ->assertOk();
        $this->assertDatabaseHas('categories', ['id' => $child, 'parent_id' => null]);
    }
}
