<?php

namespace Vayes\Serializer;

use Symfony\Component\OptionsResolver\OptionsResolver;
use function Vayes\Inflector\slugify;

class Normalizer
{
    /** @var array */
    protected $options = [];

    /**
     * Normalizer constructor.
     *
     * @param array       $options
     */
    public function __construct(array $options = [])
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $this->options = $resolver->resolve($options);
    }

    /**
     * Converts object to array recursively
     * Accepts callbacks. Defined callbacks are:
     *      'callbackPropertyCase'      => string 'functionName'
     *      'callbackExcludeProperty'   => array ['propertyName1', 'propertyName2']
     *
     * For ease of use, callback params are defined as Enum at PrimeEnum:
     * --> PrimeEnum::NORMALIZER_PROPERTY_EXCLUDE => ['id', 'name']
     * --> PrimeEnum::NORMALIZER_PROPERTY_CALLBACK => 'myFunc|arg_2|arg_3|arg_4'
     * --> PrimeEnum::NORMALIZER_PROPERTY_CASE_AUTO_CONVERT => false OR myFunc|arg_2|arg_3|...
     *
     * Notes:
     *
     * --> If no option is set to AUTO_CONVERT (camel|title => snake), default is true,
     *     and it calls PrimeEnum::NORMALIZER_PROPERTY_DEFAULT_CASE_CONVERTER function.
     *
     * --> NORMALIZER_PROPERTY_CALLBACK function takes $key parameter first (as $arg_1).
     *     i.e. str_replace|arg_2|arg_3 will works as str_replace($key, $arg_2, $arg_3)
     *          which will throw error due to argument ordering. :) Be careful!
     *
     *
     * Regex: /\\x00.*?\\x00/u clears all.
     *
     * @param       $obj
     * @param array $ignoredProperties
     * @return array
     */
    function normalize($obj, array $ignoredProperties = []): array
    {
        $cst = (array) $obj;
        $arr = [];
        $protected = [];

        foreach ($cst as $k => $v)
        {
            // Resolve protected properties
            $key = preg_replace('/\\x00\*\\x00/u', '', $k);
            if ($key !== $k) {
                $protected[] = $key;
                if (false === $this->options['includeProtectedProperties']) {
                    continue;
                }
            }

            // Resolve private|public properties
            $key = preg_replace('/\\x00.*?\\x00/u', '', $key);
            
            // Normalize DateTime objects
            if (true === $v instanceof \DateTimeInterface) {
                $v->setTimezone(new \DateTimeZone('UTC'));
                $v = $v->format("Y-m-d h:i:s");
            }
            
            $val = (is_array($v) || is_object($v)) ? $this->normalize($v) : $v;

            if (false === $this->options['includeNullValues']) {
                if (null === $val) {
                    continue;
                }
            }

            // Handle case conversion of the property names
            if (true === $this->options['convertPropertiesToSnakeCase']) {
                $funcMetaArray = explode('|', $this->options['caseConverterFunction']);
                $func = array_shift($funcMetaArray);

                if (false === function_exists($func)) {
                    throw new \BadMethodCallException("Function with `{$func}` could not be found");
                }

                $key = call_user_func_array(
                    $func,
                    array_merge(
                        (array) $key,
                        $funcMetaArray
                    )
                );
            }

            // String manipulation callbacks for keys. e.g. "str_replace|arg_2|arg_3"
            $cbPropertyOpt = 'callbackPropertyCallback';
            if (false === is_null($this->options['propertyCallback'])) {
                if (is_string($this->options['propertyCallback'])) {
                    $cbArray = explode('|', $this->options['propertyCallback']);
                    $cbFunc = array_shift($cbArray);
                    $key = call_user_func_array(
                        $cbFunc,
                        array_merge(
                            (array) $key,
                            $cbArray
                        )
                    );
                } else {
                    $cbFunc = $this->options['propertyCallback'];
                    $key = call_user_func_array(
                        $cbFunc,
                        [$key, $val]
                    );

                    if (null === $key OR false === $key) {
                        continue;
                    }
                }
            }

            $arr[$key] = $val;

            // Exclude given keys from normalized object. e.g. unset id fields
            if (false === empty($this->options['ignoredProperties'])
                && true === is_array($this->options['ignoredProperties'])) {
                if (in_array($key, $this->options['ignoredProperties'])) {
                    unset($arr[$key]);
                }
            }

            // This enables to ignore elements even with use this class as Facade.
            if (false === empty($ignoredProperties)) {
                if (in_array($key, $ignoredProperties)) {
                    unset($arr[$key]);
                }
            }
        }

        return (array) $arr;
    }

    protected function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'includeProtectedProperties' => false,
            'includeNullValues' => false,
            'propertyCallback' => null,
            'ignoredProperties' => [],
            'convertPropertiesToSnakeCase' => true,
            'caseConverterFunction' => 'vayes\str\str_snake_case_safe|_',
        ]);

        $resolver->setAllowedTypes('includeProtectedProperties', 'bool');
        $resolver->setAllowedTypes('includeNullValues', 'bool');
        $resolver->setAllowedTypes('propertyCallback', ['null', 'string', 'Closure']);
        $resolver->setAllowedTypes('ignoredProperties', 'string[]');
        $resolver->setAllowedTypes('convertPropertiesToSnakeCase', 'bool');
        $resolver->setAllowedTypes('caseConverterFunction', 'string');
    }
}
