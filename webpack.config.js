const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')

module.exports = {
    entry: path.resolve(__dirname, 'src', 'main.js'),
    output: {
        path: path.resolve(__dirname, 'js'),
        filename: 'batch_renamer.js',
    },
    resolve: {
        extensions: ['.js', '.vue', '.json'],
        fallback: {
            "string_decoder": false
        }
    },
    module: {
        rules: [
            { test: /\.vue$/, loader: 'vue-loader' },
            { test: /\.css$/, use: ['vue-style-loader', 'css-loader'] },
            { test: /\.js$/, exclude: /node_modules/, loader: 'babel-loader' }
        ]
    },
    plugins: [new VueLoaderPlugin()]
}