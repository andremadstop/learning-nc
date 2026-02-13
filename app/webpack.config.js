const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');

module.exports = {
  entry: './src/main.js',
  output: {
    path: path.resolve(__dirname, 'js'),
    filename: 'learning.js',
    publicPath: '/apps/learning/js/'
  },
  module: {
    rules: [
      {
        test: /\.vue$/,
        loader: 'vue-loader'
      },
      {
        test: /\.js$/,
        loader: 'babel-loader',
        exclude: /node_modules/
      },
      {
        test: /\.css$/,
        use: ['style-loader', 'css-loader']
      }
    ]
  },
  plugins: [
    new VueLoaderPlugin()
  ],
  resolve: {
    extensions: ['.js', '.vue'],
    alias: {
      vue$: 'vue/dist/vue.esm.js'
    },
    fallback: {
      path: false,
      string_decoder: false
    }
  },
  devtool: false,
  externals: {
    linkifyjs: 'linkifyjs'
  }
};
