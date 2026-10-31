<?php

namespace Tests\Feature\Shell\Profile;

use App\Shell\Profile\ProfileValidation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\Feature\Tenancy\TenancyTestCase;

final class ProfileValidationTest extends TenancyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_profile_and_email_validation_messages_are_readable_russian(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->putJson('/profile', ['name' => '', 'timezone' => 'Mars/Olympus', 'language' => 'ru'])
            ->assertUnprocessable()->assertJsonPath('errors.name.0', 'Заполните поле «имя».')
            ->assertJsonPath('errors.timezone.0', 'Выберите допустимое значение поля «часовой пояс».');
        $this->postJson('/profile/email', ['email' => 'not-an-email', 'current_password' => 'test-only-password'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Укажите корректный адрес электронной почты.');
    }

    public function test_password_rule_keeps_minimum_and_confirmation_with_russian_messages(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->putJson('/profile/security/password', ['current_password' => 'test-only-password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Пароль должен содержать не менее 12 символов.');
        $this->putJson('/profile/security/password', ['current_password' => 'test-only-password', 'password' => 'new-valid-password', 'password_confirmation' => 'different-valid-password'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Пароли не совпадают.');
    }

    public function test_mfa_code_and_current_password_errors_are_localized(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/profile/security/mfa/confirm', ['code' => 'abc'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Поле «код подтверждения» должно содержать 6 цифр.');
        $this->postJson('/profile/security/mfa/setup', ['password' => 'wrong'])
            ->assertUnprocessable()->assertJsonPath('errors.password.0', 'Текущий пароль указан неверно.');
        $this->postJson('/profile/security/mfa/disable', ['password' => 'test-only-password', 'code' => '123'])
            ->assertUnprocessable()->assertJsonPath('errors.code.0', 'Поле «код подтверждения» должно содержать 6 цифр.');
    }

    public function test_avatar_mime_and_notification_payload_errors_are_localized(): void
    {
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->postJson('/profile/avatar', ['avatar' => UploadedFile::fake()->create('bad.svg', 1, 'image/svg+xml')])
            ->assertUnprocessable()->assertJsonPath('errors.avatar.0', 'Для поля «аватар» разрешены файлы: png, jpg, jpeg, webp.');
        $this->putJson('/profile/notifications', ['changes' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('errors.changes.0', 'Поле «настройки уведомлений» должно содержать список допустимых значений.');
        $this->postJson('/profile/notifications/test', ['channel' => 'invalid', 'request_key' => 'invalid'])
            ->assertUnprocessable()->assertJsonPath('errors.channel.0', 'Выберите допустимое значение поля «канал».')
            ->assertJsonPath('errors.request_key.0', 'Обновите страницу и повторите запрос.');
    }

    public function test_replacing_avatar_changes_its_versioned_url_and_preserves_brand_timezone(): void
    {
        Storage::fake('local');
        [$user, , $brand] = $this->cabinet();
        $this->actingAs($user)->withSession(['brand_id' => $brand->id]);
        $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('first.png', 32, 32)])->assertRedirect();
        $first = $this->get('/profile')->assertOk()->viewData('page')['props']['profile']['avatar_url'];
        $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('second.png', 32, 32)])->assertRedirect();
        $profile = $this->get('/profile')->assertOk()->viewData('page')['props']['profile'];
        $this->assertNotSame($first, $profile['avatar_url']);
        $this->assertStringContainsString('/profile/avatar?v=', $profile['avatar_url']);
        $this->assertSame($brand->fresh()->timezone, $profile['brand_timezone']);
        $this->get($profile['avatar_url'])->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_feature_messages_cover_scalar_size_and_nested_password_rules(): void
    {
        $validator = Validator::make([
            'name' => ['invalid'], 'phone' => 'invalid', 'changes' => [], 'avatar' => 'invalid',
            'password' => 'lowercase-only', 'enabled' => 'invalid',
        ], [
            'name' => ['string'], 'phone' => ['regex:/^[0-9]+$/'], 'changes' => ['array', 'min:1'],
            'avatar' => ['file'], 'password' => [Password::min(12)->mixedCase()->numbers()->symbols()], 'enabled' => ['boolean'],
        ], ProfileValidation::messages(), [...ProfileValidation::attributes(), 'enabled' => 'уведомление']);
        $this->assertTrue($validator->fails());
        foreach ($validator->errors()->all() as $message) {
            $this->assertStringNotContainsString('validation.', $message);
            $this->assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $message);
        }
    }
}
