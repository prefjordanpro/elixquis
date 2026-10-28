<?php
namespace App\Form;

use EasyCorp\Bundle\EasyAdminBundle\Form\DataTransformer\StringToFileTransformer;
use EasyCorp\Bundle\EasyAdminBundle\Form\Type\FileUploadType;
use Symfony\Component\Form\{CallbackTransformer, FormBuilderInterface, FormInterface, FormView};
use Symfony\Component\HttpFoundation\File\{File, UploadedFile};

/** Keeps existing editorial subdirectories when EasyAdmin returns unchanged images. */
final class ContentImageType extends FileUploadType
{
    public function __construct(#[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.project_dir%')] private readonly string $projectDir, \Symfony\Component\Filesystem\Filesystem $filesystem)
    {
        parent::__construct($projectDir, $filesystem);
    }
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);
        $transformer = new StringToFileTransformer($options['upload_dir'], $options['upload_filename'], $options['upload_validate'], false);
        $builder->resetModelTransformers();
        $builder->addModelTransformer(new CallbackTransformer(
            $transformer->transform(...),
            static function ($file) use ($transformer, $options) {
                if ($file instanceof File && !$file instanceof UploadedFile) {
                    $root = str_replace('\\', '/', $options['upload_dir']);
                    return substr(str_replace('\\', '/', $file->getPathname()), strlen($root));
                }
                return $transformer->reverseTransform($file);
            }
        ));
    }
    public function getBlockPrefix(): string { return 'content_image'; }
    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        parent::buildView($view, $form, $options);
        $view->vars['content_image_url'] = null;
        $public = str_replace('\\', '/', $this->projectDir).'/public/';
        foreach ($view->vars['currentFiles'] as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (str_starts_with($path, $public) && preg_match('~\.(?:jpe?g|png|webp)$~i', $path)) {
                $view->vars['content_image_url'] = substr($path, strlen($public));
                break;
            }
        }
    }
}
