import { parse } from 'node-html-parser';
import { readFileSync, writeFileSync  } from 'fs';
import axios from 'axios';

// read the file from the first thing passed to the script and parse it into an array
const file = process.argv[2];
const version = process.argv[3];

if ( ! version ) {
	process.exit(1);
}

const data = readFileSync( file, 'utf8' ).split( '\n' ).filter( l => l.length > 0 );

const profiles = new Map();
const newCredits = [];


const response = await axios.get( 'https://api.wordpress.org/core/credits/1.1/?version=' + version );

const props = Object.keys( response.data.groups.props.data ).map( s => s.toLowerCase() );
const contributing = Object.keys( response.data.groups['contributing-developers'].data ).map( s => s.toLowerCase() );
const core = Object.keys( response.data.groups['core-developers'].data ).map( s => s.toLowerCase() );

// use a set to dedule with ease
const credits = [...new Set([ ...props, ...contributing, ...core ] )];

// for each element in data
for( const profile of data ) {
    // check if profile is in the set
    if( profiles.has( profile.toLowerCase() ) ) {
        // do nothing
    } else { 
		try{
			const baseUrl = 'https://profiles.wordpress.org/';
			// get the profile page, urlendcode the profile name
			const url = baseUrl + encodeURIComponent( profile );
			// fetch url using axios and throw an error if it is not a 200
			const response = await axios.get( url ).catch( err => { throw profile; } );
			// parse the response into an html document
			const html = parse( response.data );
			const name = html.querySelector( '.site-header h2' ).text;
			// create the HTML for the link
			const link = `<a href="${url}">${name}</a>`;
			// add the link to the set
			profiles.set( profile.toLowerCase(), { link, name } );
			// check if profile is in the credits and if not add it to the newCredits array
			if( !credits.includes( profile.toLowerCase() ) ) {
				newCredits.push( profile );
				props.push(profile.toLowerCase());
			}
		} catch ( e ) {
			console.log( `Error with ${profile}` );
		}
    }
};

// take the profiles Map and sort them based on the name
const sortedProfiles = new Map( [...profiles.entries()].sort( ( a, b ) => a[1].name.localeCompare( b[1].name ) ) );

// sort the credits. 
newCredits.sort()
props.sort();

// create the html for the liss and join them with a comma
const html = [...sortedProfiles.values()].map( p => p.link ).join( ', ' );
console.log( '-----')
console.log( html );
console.log( '-----')
console.log( 'New Credits:' );
console.log( newCredits.sort() );

// the credids
const creditsContent = props.map(credit => `'${credit}'`).join(',\n');
writeFileSync(`${file}.credits.txt`, creditsContent, 'utf8');

console.log( `updated credits array written to ${file}.credits.txt` );