# WordPress Profile Link Generator

This is the script I use to generate the list of profile links used in minor releases. To use:

1. `npm install` to install dependencies.
1. Create a file with a list of usernames. Each username should be on it's own line
1. run `node index.js FILE MAJOR` for example, if your file is named `data/6.5.4`, and you want to create the credits for 6.5.4, you would run `node index.js data/6.5.4 6.5`
1. Copy the HTML for use in the WordPress post editor
1. Use the file created to create a diff of the appropirate file in https://meta.trac.wordpress.org/browser/sites/trunk/api.wordpress.org/public_html/core/credits .  Copy that data and replace the data in the `props` method. 
