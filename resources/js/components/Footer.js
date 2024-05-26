import React from "react";
import "./styles.css"
import { HiOutlineLocationMarker } from "react-icons/hi";
import { BsEnvelopeAt } from "react-icons/bs";
import { FiPhoneCall } from "react-icons/fi";
import { ImFacebook2 } from "react-icons/im";
import { BsLinkedin } from "react-icons/bs";
import { FaInstagramSquare } from "react-icons/fa";

const Footer = () => {
    return (
        <footer>
            <div class="footer-top">
                <div class="container">
                    <div class="footer-day-time">
                        <div class="row">
                            <div class="col-md-8">
                                <ul>
                                    <li>Opening Hours: Mon - Friday: 8AM - 5PM</li>
                                    <li>Sunday: 8:00 AM - 12:00 PM</li>
                                </ul>
                            </div>
                            <div class="col-lg-4">
                                <div class="phone-no">
                                    {/* <a href="tel:+8454029747"><i class="fa fa-mobile" aria-hidden="true"></i>Call +8454029747</a> */}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-4">

                            <h4>About us</h4>
                            <p>Lorem Ipsum ist einfach Dummy-Text der Druck- und Satzindustrie. Lorem Ipsum war der Standard der Branche Lorem Ipsum ist einfach Dummy-Text der Druck- und Satzindustrie. Lorem Ipsum war der Standard der Branche  </p>

                        </div>

                        <div class="col-md-4">
                            <h4>Information</h4>
                            <ul class="address1">
                                <li><HiOutlineLocationMarker /> Lorem Ipsum 132 xyz Lorem Ipsum</li>
                                <li><BsEnvelopeAt /><a href="mailto:support@tourkokan.com"> support@tourkokan.com</a></li>
                                <li><FiPhoneCall /> <a href="tel:8454029747"> 8454029747</a></li>
                            </ul>
                        </div>

                        <div class="col-md-4">
                            <h4>Follow us</h4>
                            <ul class="social-icon">
                                <li><ImFacebook2 size={20}/></li>
                                <li><FaInstagramSquare size={22}/></li>
                                <li><BsLinkedin size={20}/></li>
                            </ul>
                        </div>

                    </div>
                </div>
            </div>
            <div class="footer-bottom">
                <div class="container">
                    <div class="row">
                        <div class="col-sm-5">
                            <p class="copyright text-uppercase">Copyright © 2018
                            </p>
                        </div>
                        <div class="col-sm-7">
                            <ul>
                                <li><a href="/">Home</a></li>
                                <li><a href="/#About">About Us</a></li>
                                <li><a href="/#Contact">Contact Us</a></li>
                                <li><a href="PrivacyPolicy">Privacy Policy</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    )
}

export default Footer;