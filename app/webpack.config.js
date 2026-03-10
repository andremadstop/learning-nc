const path = require('path');
const { VueLoaderPlugin } = require('vue-loader');
const webpack = require('webpack');

module.exports = {
  entry: {
    learning: './src/main.js',
    'learning-admin-settings': './src/admin-settings.js',
    'learning-personal-settings': './src/personal-settings.js',
  },
  output: {
    path: path.resolve(__dirname, 'js'),
    filename: '[name].js',
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
    new VueLoaderPlugin(),
    new webpack.DefinePlugin({
      appName: JSON.stringify('learning'),
      appVersion: JSON.stringify('0.1.0')
    })
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
