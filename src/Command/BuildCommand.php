<?php

namespace WPArtisan\Command;

use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use RecursiveCallbackFilterIterator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;

class BuildCommand extends Command
{

    public $packages = [
                'free' => 'wp-native-articles',
                'premium' => 'wp-native-articles-premium'
            ];

    public $currentPackage = null;

    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Builds packages')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        $buildDir = 'build';

        $fs = new Filesystem();

        try {

            // Remove any existing build dir.
            $fs->remove( "./{$buildDir}" );

            // Setup the package dirs
            foreach ( (array) $this->packages as $key => $name )
            {
                $fs->mkdir( sprintf( './%s/%s', $buildDir, $name ) );
            }

            $output->writeln( '<info>Build directory created</info>' );

            // Work out the files that don't need copying
            $exclude = [
                $buildDir,
                '.git',
                'vendor',
                'source-generator',
                '.DS_Store',
                '.gitignore',
                'circle.yml',
                'composer.json',
                'composer.lock',
            ];

            // Filter out the exclude folders & files.
            $excludeFilter = function( $fileInfo, $key, $iterator ) use ( $exclude ) {
                $str = $fileInfo->getPathname();
                $prefix = './';
                if ( substr( $str, 0, strlen( $prefix ) ) === $prefix ) {
                    $str = substr( $str, strlen( $prefix ) );
                }
                return ! in_array( $str, $exclude );
            };

            // Filter out the package files
            $packagesFilter = function( $fileInfo, $key, $iterator ) {

                // Check the filename.
                foreach ( $this->packages as $package => $name )
                {
                    // Include all files for the current package.
                    if ( $this->currentPackage === $package )
                        continue;

                    // If it belongs to another package ignore it.
                    if ( false !== $pos = strrpos( $fileInfo->getPathname(), '__is' . ucfirst( $package ) ) )
                        return false;

                }

                // Return true if they're made it here.
                return true;
            };

            // Setup the Directory Iterator.
            $directoryIterator = new RecursiveDirectoryIterator( './' );

            // Load in the exclude filter.
            $filterIterator = new RecursiveCallbackFilterIterator( $directoryIterator, $excludeFilter );

            $iterators = array();

            foreach ( (array) $this->packages as $key => $name ) {

                // Setup the Recursive Interator and pass in the filters.
                $iterators[ $key ] = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        $filterIterator,
                        $packagesFilter
                    )
                );

            }

            // Iterator over our iterators.
            foreach ( $iterators as $package => $packageIterator ) {

                // Set the current package we're working on.
                $this->currentPackage = $package;

                // Interator over every file in the iterator.
                foreach ( $packageIterator as $pathname => $fileInfo ) {

                    $filteredPathname = $pathname;

                    $searchFor = '__is' . ucfirst( $package );

                    if ( false !== $pos = strrpos( $fileInfo->getPathname(), $searchFor ) ) {
                        $filteredPathname = str_ireplace( $searchFor, '', $pathname );
                    }

                    // Workout the new path.
                    $newPath = sprintf( './%s/%s/%s', $buildDir, $this->packages[ $package ], $filteredPathname );

                    // If it's a dir
                    if ( $fileInfo->isDir() )
                    {
                        $fs->mkdir( $newPath );
                    }

                    // If it's a file
                    if ( $fileInfo->isFile() )
                    {
                        // Copy the file.
                        $fs->copy( $pathname, $newPath );

                        if ( 'php' === $fileInfo->getExtension() )
                        {
                            // Parse for package specific code.
                            $this->parseFile( $newPath );
                        }

                    }

                }

                $output->writeln( '<info>Built package: ' . $this->packages[ $package ] . '</info>' );

            }


        } catch ( IOExceptionInterface $e ) {
            $output->writeln('<error>Error: ' . $e->getPath() . ' could not be created</error>');
        }

    }

    /*
     * Parses each file and remove package specific code.
     */
    public function parseFile( $path ) {

        $packagesToParse = array_keys( $this->packages );

        // Tokenise the file.
        $tokens = token_get_all( file_get_contents( $path ) );

        // Remove these parts from the file.
        $elementsToRemove = array();

        // Loop over the packages.
        foreach ( $packagesToParse as $package )
        {
            // If it's the curent package work out the IF statments that need removing.
            if ( $this->currentPackage === $package )
            {

                // Iterator over the tokens.
                foreach ( $tokens as $i => $token )
                {
                    // If it's not an array continue.
                    if ( ! is_array( $token ) )
                    {
                        continue;
                    }

                    // We only want IF statments this time
                    if ( 'T_STRING' == token_name( $token[0] ) && $token[1] === '__is' . ucfirst( $package ) )
                    {

                        // Find start of the IF statement
                        $k = $i - 1;
                        $removeFrom = null;
                        while ( $k >= 0 && is_null( $removeFrom ) )
                        {
                            if ( is_array( $tokens[ $k ] ) && 'T_IF' === token_name( $tokens[ $k ][0] ) )
                            {
                                $removeFrom = $k;

                                // Remove 1 line break before the start to tidy up.
                                if ( 'T_WHITESPACE' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                                    $removeFrom = $removeFrom - 1;
                                }

                                break;
                            }
                            --$k;
                        }

                        // Find opening IF statement bracket
                        $k = $i + 1;
                        $removeTo = null;
                        while ( $k <= count( $tokens ) && is_null( $removeTo ) )
                        {
                            if ( ! is_array( $tokens[ $k ] ) && '{' === $tokens[ $k ] )
                            {
                                $removeTo = $k;
                                break;
                            }
                            $k++;
                        }

                        // Add to the remove elements.
                        $elementsToRemove[] = array( $removeFrom, $removeTo );

                        // Find closing IF statment bracket.
                        $j = $i + 1;
                        $bracketsFound = 0;
                        $closingBracketLocation = null;
                        while ( $j < count( $tokens ) && is_null( $closingBracketLocation ) ) {

                            // If it's not the token we're looking for.
                            if ( is_array( $tokens[ $j ] ) || '}' !== $tokens[ $j ] ) {

                                // If it's an opening bracket then increment the closing bracket
                                // number to search for.
                                if ( '{' === $tokens[ $j ] ) {
                                    $bracketsFound++;
                                }

                            } else {
                                // Found a closing bracket. Remove it from the search.
                                $bracketsFound--;

                                // Check if it's the last one we need to find.
                                if ( 0 === $bracketsFound ) {
                                    $closingBracketLocation = $j;
                                    break;
                                }

                            }
                            $j++;
                        }

                        // Add to the remove elements.
                        $elementsToRemove[] = array( $closingBracketLocation, $closingBracketLocation );

                    }


                }


                continue;
            }

            // Iterate again but this time for all the other packages, not the current one.

            // Iterator over the tokens.
            foreach ( $tokens as $i => $token )
            {

                // If it's not an array continue.
                if ( ! is_array( $token ) )
                {
                    continue;
                }

                // Check for variables.
                if ( 'T_VARIABLE' == token_name( $token[0] ) && false !== strrpos( $token[1], '__is' . ucfirst( $package ) ) ) {

                    // Find the start.
                    $removeFrom = $i;

                    // Back one if it's white space.
                    if ( 'T_WHITESPACE' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                        $removeFrom = $removeFrom - 1;
                    }

                    // Back one if it's an access variable.
                    if ( in_array( token_name( $tokens[ $removeFrom - 1 ][0] ), [ 'T_PRIVATE', 'T_PROTECTED', 'T_PUBLIC' ], true ) ) {
                        $removeFrom = $removeFrom - 1;
                    }

                    // Back one if it's white space.
                    if ( 'T_WHITESPACE' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                        $removeFrom = $removeFrom - 1;
                    }

                    // Back one if it's a comment.
                    if ( 'T_DOC_COMMENT' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                        $removeFrom = $removeFrom - 1;
                    }

                    // Back one if it's white space.
                    if ( 'T_WHITESPACE' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                        $removeFrom = $removeFrom - 1;
                    }

                    $j = $i + 1;
                    $removeTo = null;
                    while ( $j < count( $tokens ) && is_null( $removeTo ) ) {

                        // If it's not the token we're looking for.
                        if ( ! is_array( $tokens[ $j ] ) && ';' === $tokens[ $j ] ) {

                            $removeTo = $j;
                        }

                        $j++;

                    }

                    $elementsToRemove[] = array( $removeFrom, $removeTo );

                }

                if ( 'T_STRING' == token_name( $token[0] ) && false !== strrpos( $token[1], '__is' . ucfirst( $package ) ) ) {

                    // Find opening IF statement
                    $k = $i - 1;
                    $removeFrom = null;
                    while ( $k >= 0 && is_null( $removeFrom ) ) {
                        if ( ! is_array( $tokens[ $k ] ) || ! in_array( token_name( $tokens[ $k ][0] ), [ 'T_IF', 'T_FUNCTION' ], true ) ) {
                            --$k;
                        } else {

                            $removeFrom = $k;

                            // If it's a function check for comments and ting.
                            if ( 'T_FUNCTION' === token_name( $tokens[ $k ][0] ) ) {

                                // If it's a function check for visibility.
                                if ( in_array( token_name( $tokens[ $removeFrom - 2 ][0] ), [ 'T_PRIVATE', 'T_PROTECTED', 'T_PUBLIC' ], true ) ) {
                                    $removeFrom = $removeFrom - 2;
                                }

                                // If it's got a comment directly preceding it remove that as well.
                                if ( 'T_DOC_COMMENT' === token_name( $tokens[ $removeFrom - 2 ][0] ) ) {
                                    $removeFrom = $removeFrom - 2;
                                }

                            }

                            // Remove 1 line break before the start to tidy up.
                            if ( 'T_WHITESPACE' === token_name( $tokens[ $removeFrom - 1 ][0] ) ) {
                                $removeFrom = $removeFrom - 1;
                            }

                        }
                    }

                    // Find closing bracket
                    $j = $i + 1;
                    $bracketsFound = 0;
                    $removeTo = null;
                    while ( $j < count( $tokens ) && is_null( $removeTo ) ) {

                        // If it's not the token we're looking for.
                        if ( is_array( $tokens[ $j ] ) || '}' !== $tokens[ $j ] ) {

                            // If it's an opening bracket then increment the closing bracket
                            // number to search for.
                            if ( '{' === $tokens[ $j ] ) {
                                $bracketsFound++;
                            }

                        } else {
                            // Found a closing bracket. Remove it from the search.
                            $bracketsFound--;

                            // Check if it's the last one we need to find.
                            if ( 0 === $bracketsFound ) {
                                $removeTo = $j;
                                break;
                            }

                        }

                        $j++;

                    }

                    $elementsToRemove[] = array( $removeFrom, $removeTo );

                }

            }

        }


        // Run the loop again so we can put the file back together.
        // Stores the parts of the file so it can be put back together again.
        $fileContents = array();
        foreach ( $tokens as $i => $token )
        {
            $fileContents[ $i ] = is_array( $token ) ? $token[1] : $token;
        }

        // Remove all the found package specific code from the contents.
        // Simple way to remove multi parts from the array well maintaing keys.
        foreach ( $elementsToRemove as $element )
        {
            for ( $i = $element[0]; $i <= $element[1]; $i++ )
            {
                unset( $fileContents[ $i ] );
            }
        }

        // Put the content back together.
        $fileString = implode( '', $fileContents );

        // Tidy up by removing the current the package string.
        $fileString = str_replace( '__is' . ucfirst( $this->currentPackage ), '', $fileString );

        // Put the file back together again.
        file_put_contents( $path, $fileString );

    }


}