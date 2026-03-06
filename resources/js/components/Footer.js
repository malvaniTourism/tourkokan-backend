import React from "react";
import "./styles.css";
import { HiOutlineLocationMarker } from "react-icons/hi";
import { BsEnvelopeAt } from "react-icons/bs";
import { FiPhoneCall } from "react-icons/fi";
import { ImFacebook2 } from "react-icons/im";
import { FaInstagramSquare } from "react-icons/fa";

const Footer = () => {
    return (
        <footer>
            <div className="footer-top">
                <div className="container">
                    <div className="row g-4">
                        <div className="col-lg-4 col-md-6">
                            <h4>About Us</h4>
                            <p>Welcome to TourKokan, your ultimate guide to exploring the hidden gems of Kokan! Our app showcases unique places and local attractions. Discover the rich culture, historical sites, and natural beauty of Kokan through our curated content. Whether you're seeking adventure, cultural experiences, or serene getaways, TourKokan helps you plan the perfect trip.</p>
                        </div>

                        <div className="col-lg-4 col-md-6">
                            <h4>Information</h4>
                            <ul className="address1">
                                <li>
                                    <HiOutlineLocationMarker size={18} />
                                    <span>402, Sai Anand CHS, Gopinath Chawk, Dombivali (W), 421202</span>
                                </li>
                                <li>
                                    <BsEnvelopeAt size={18} />
                                    <a href="mailto:support@tourkokan.com">support@tourkokan.com</a>
                                </li>
                                <li>
                                    <FiPhoneCall size={18} />
                                    <a href="tel:8454029747">8454029747</a>
                                </li>
                            </ul>
                        </div>

                        <div className="col-lg-4 col-md-6">
                            <h4>Follow Us</h4>
                            <ul className="social-icon">
                                <li>
                                    <a href="https://www.facebook.com/people/Tourkokan/61560289596939/?mibextid=LQQJ4d" aria-label="Facebook">
                                        <ImFacebook2 size={28} />
                                    </a>
                                </li>
                                <li>
                                    <a href="https://www.instagram.com/tour_kokan/" aria-label="Instagram">
                                        <FaInstagramSquare size={28} />
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <div className="footer-bottom">
                <div className="container">
                    <div className="footer-bottom-inner">
                        <ul>
                            <li><a href="/Terms">Terms &amp; Conditions</a></li>
                            <li><a href="/PrivacyPolicy">Privacy Policy</a></li>
                        </ul>
                    </div>
                </div>
            </div>
        </footer>
    );
};

export default Footer;
