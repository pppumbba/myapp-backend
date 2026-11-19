<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('api')->plainTextToken, 'Accept' => 'application/json'];
    }

    /** A real PNG without GD. */
    private function png(int $width, int $height): UploadedFile
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat(chr(0).str_repeat(chr(0x33).chr(0x66).chr(0x99), $width), $height);

        return UploadedFile::fake()->createWithContent('me.png', chr(0x89).'PNG'.chr(13).chr(10).chr(26).chr(10)
            .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', gzcompress($rows))
            .$chunk('IEND', ''));
    }

    public function test_user_uploads_replaces_and_removes_their_picture(): void
    {
        Storage::fake('avatars');
        $user = User::factory()->create();
        $auth = $this->signIn($user);

        $first = $this->post('/api/user/avatar', ['avatar' => $this->png(64, 64)], $auth)->assertOk()->json('avatar');
        $this->assertMatchesRegularExpression('/^'.$user->id.'-[A-Za-z0-9]{16}\.png$/', $first);
        Storage::disk('avatars')->assertExists($first);

        // Served without a token, so an <img> can show it.
        $this->get('/api/avatars/'.$first)->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

        $second = $this->post('/api/user/avatar', ['avatar' => $this->png(80, 40)], $auth)->assertOk()->json('avatar');
        $this->assertNotSame($first, $second);
        Storage::disk('avatars')->assertMissing($first);
        $this->get('/api/avatars/'.$first)->assertNotFound();

        $this->deleteJson('/api/user/avatar', [], $auth)->assertOk()->assertJsonPath('avatar', null);
        Storage::disk('avatars')->assertMissing($second);
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_only_pictures_are_taken(): void
    {
        Storage::fake('avatars');
        $auth = $this->signIn(User::factory()->create());

        $this->post('/api/user/avatar', ['avatar' => UploadedFile::fake()->createWithContent('x.png', '<?php echo 1;')], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
        $this->post('/api/user/avatar', ['avatar' => $this->png(16, 16)], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
        $this->post('/api/user/avatar', ['avatar' => $this->png(64, 64)->size(6000)], $auth)
            ->assertUnprocessable()->assertJsonValidationErrors(['avatar']);
        $this->get('/api/avatars/..%2F.env')->assertNotFound();
    }
}
