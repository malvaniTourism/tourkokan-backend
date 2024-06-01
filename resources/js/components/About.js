import React from "react";
import { Container, Row, Col, Button } from 'react-bootstrap';

const About = () => {
    return (
        <div style={{ backgroundColor: "white", padding: "50px 0" }}>
            <Container>
                <Row>
                    <Col>
                        <h1 className="text-center mb-5">About Us</h1>
                        <p>Welcome to <strong>TourKokan</strong>, your ultimate guide to exploring the breathtaking beauty and cultural richness of the Konkan region. Our app is designed to provide you with all the resources you need to make your journey through Konkan an unforgettable experience.</p>

                        <h2 className="mt-4">Discover Konkan</h2>
                        <p>The Konkan coast, stretching along the western shores of India, is a treasure trove of natural wonders, historical landmarks, and vibrant culture. From pristine beaches and lush green landscapes to ancient temples and bustling local markets, Konkan has something for every traveler. With TourKokan, you can explore popular destinations like Devgad Beach, Malvan, Ratnagiri, and many more hidden gems.</p>

                        <h2 className="mt-4">Why Choose TourKokan?</h2>
                        <p>TourKokan stands out for its comprehensive approach to travel guidance. Our app not only provides detailed information about tourist spots but also offers real-time updates, user reviews, and interactive maps. Whether you are looking for adventure, relaxation, or cultural immersion, TourKokan ensures you have the best resources at your fingertips.</p>

                        <h2 className="mt-4">Our Commitment to You</h2>
                        <p>We are committed to enhancing your travel experience with reliable and up-to-date information. Our team continuously works to expand our database with new destinations, updated bus timetables, and the latest events in Konkan. Your satisfaction and enjoyment are our top priorities.</p>

                        <h2 className="mt-4">Connect with Us</h2>
                        <p>We love hearing from our users! Whether you have a question, need assistance, or want to share your travel stories, feel free to reach out to us through our app. Join our community of explorers and make the most out of your Konkan adventure with TourKokan.</p>

                    </Col>
                </Row>
            </Container>
        </div>
    )
}

export default About;