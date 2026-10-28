<?php
namespace App\Controller\Admin;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Validator\Constraints\Image;
final class ContentImage
{
    public static function field(string $property, string $label, bool $fullPath = false): ImageField
    {
        return ImageField::new($property, $label)
            ->setFormType(\App\Form\ContentImageType::class)
            ->setBasePath($fullPath ? '/' : '/uploads')
            ->setUploadDir($fullPath ? 'public' : 'public/uploads')
            ->setUploadedFileNamePattern(($fullPath ? 'uploads/' : '').'[randomhash].[extension]')
            ->setFileConstraints([self::constraint()])
            ->setFormTypeOption('attr', ['data-image-replacement' => ''])
            ->setFormTypeOption('upload_new', static function (\Symfony\Component\HttpFoundation\File\UploadedFile $file, string $directory, string $filename): void {
                // Symfony File::move deliberately strips directories from its filename argument.
                // Our generated path may include uploads/ to preserve old editorial locations.
                if (!preg_match('~^(?:uploads/)?[a-f0-9]{40}\.(?:jpe?g|png|webp)$~D', $filename)) {
                    throw new \LogicException('Chemin d’image généré invalide.');
                }
                $file->move($directory.'/'.dirname($filename), basename($filename));
            })
            ->setFormTypeOption('upload_delete', static function (): void {})
            ->setFormTypeOption('allow_delete', false)
            ->setRequired(false)
            ->setHelp('JPEG, PNG ou WebP, 5 Mo maximum. Les anciennes images sont conservées pour préserver les références partagées.');
    }
    public static function constraint(): Image
    {
        return new Image(maxSize: '5M', mimeTypes: ['image/jpeg', 'image/png', 'image/webp']);
    }
}
