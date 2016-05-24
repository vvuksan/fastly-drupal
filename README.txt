Fastly
=======================

http://drupal.org/project/fastly

What Is Fastly?
---------------

Fastly is a CDN (Content Delivery Network), which is to say, we speed up
delivery of your website and its content to your users. When your client in
Moscow, Montana or Vriezenveen, Netherlands clicks on your site requesting
a piece of information, we want them to feel your speed of delivery. No
waiting. We want them to get to your closest point of presence—in
milliseconds—to get what they want.

Founded in 2011 Fastly delivers the world's only real-time content delivery
network. At Fastly we think slow is unacceptable. Fastly enables a
next-generation of businesses to give their users the best online and mobile
experience. The patent-pending Fastly Caching Software delivers static,
dynamic and streaming content with the lowest recorded time to first byte.
Our customers include (but certainly aren't limited to) Twitter, Guardian
UK, GitHub, AddThis, Wikia, Shazam, Wanelo, and Yammer.

Fastly is a San Francisco based company, located in the heart of SOMA.
We are venture backed by Battery Ventures, OATV, August Capital, and Amplify
Partners. We provide challenging work, opportunities to learn, high-quality
teammates and, most importantly, we have a lot of fun doing what we love.
We offer some great benefits like a Tahoe ski cabin, stocked fridge,
flexible schedules, lots of company happy hours, and a no-vacation policy.
We are close to MUNI and BART, and Caltrain is just a pleasant 15-minute stroll.

We offer competitive compensation, stock options, and health benefits.


Module Features
---------------

1. Account Sign In. If you are already an authenticated Fastly user,
You can simply enter your API key and service ID to start using Fastly
on your website.

2. Automated Purging. Content will be automatically purged when updated/created.
This is done using Drupal 8's CacheTagsInvalidatorInterface.

How To Install The Module?
--------------------------

1. Install Fastly (unpacking it to your Drupal
/modules directory if you're installing by hand, for example).

2. Fastly will appear in your Configuration > Web services menu section.

3. Enter your Fastly API key in the settings form and then select your service.

If you find a problem, incorrect comment, obsolete or improper code or such,
please search for an issue about it at http://drupal.org/project/fastly/issues
If there isn't already an issue for it, please create a new one.

TLS and Fastly
--------------
Fastly can support TLS connections.
See https://docs.fastly.com/guides/securing-communications for a list showing
different options available. If you are using TLS, you should add the following
lines of code to your settings.php

// Enable Faslty TLS connections.
if (!empty($_SERVER['HTTP_FASTLY_SSL'])) {
  $_SERVER['HTTPS'] = 'on';
}
