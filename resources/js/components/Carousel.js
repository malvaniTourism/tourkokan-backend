import React from 'react';
import Carousel from 'react-bootstrap/Carousel';

function MyCarousel() {
    return (
        <Carousel data-bs-theme="dark">
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{ height: "90vh" }}
                    src="/assets/corousel/Devgad_beach.jpg"
                    alt="First slide"
                />
                <Carousel.Caption>
                    <div style={{ color: "#FFFFFF", backgroundColor: "rgba(0, 0, 0, 0.5)", padding: "10px" }}>
                        <h1 style={{ color: "white" }}>Devgad Beach: A Tranquil Coastal Paradise</h1>
                        <p style={{ color: "white" }}>
                            Nestled along the Konkan coast, Devgad Beach is a serene escape characterized by its pristine sands and azure waters. Renowned for its picturesque sunsets and gentle waves, this beach offers a perfect retreat for those seeking peace and natural beauty. Visitors can explore the historic Devgad Fort nearby or simply relax and soak in the tranquil ambiance. Whether you're a nature lover, a history enthusiast, or just looking for a quiet getaway, Devgad Beach promises an unforgettable experience.
                        </p>
                    </div>
                </Carousel.Caption>
            </Carousel.Item>
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{ height: "90vh" }}
                    src="/assets/corousel/Malvan.jpg"
                    alt="Second slide"
                />
                <Carousel.Caption>
                    <div style={{ color: "#FFFFFF", backgroundColor: "rgba(0, 0, 0, 0.5)", padding: "10px" }}>
                        <h1 style={{ color: "white" }}>Malvan Scuba Diving: Dive into the Underwater Wonderland</h1>
                        <p style={{ color: "white" }}>
                            Experience the thrill of underwater exploration with Malvan Scuba Diving. Located on the stunning Konkan coast, Malvan offers crystal-clear waters teeming with vibrant marine life and colorful coral reefs. Whether you're a beginner or an experienced diver, the serene and shallow waters make for a perfect diving adventure. Discover the beauty of the Arabian Sea's hidden treasures and create unforgettable memories. Join us in Malvan for an exhilarating dive into an underwater paradise!
                        </p>
                    </div>
                </Carousel.Caption>
            </Carousel.Item>
            <Carousel.Item>
                <img
                    className="d-block w-100"
                    style={{ height: "90vh" }}
                    src="/assets/corousel/agnewadi.jpg"
                    alt="Third slide"
                />
                <Carousel.Caption>
                    <div style={{ color: "#FFFFFF", backgroundColor: "rgba(0, 0, 0, 0.5)", padding: "10px" }}>
                        <h1 style={{ color: "white" }}>Agnewadi Bharadi Devi: A Divine Pilgrimage</h1>
                        <p style={{ color: "white" }}>
                            Nestled in the picturesque village of Angnewadi, the Bharadi Devi Temple is a revered pilgrimage site known for its spiritual significance and vibrant festivals. Dedicated to Goddess Bharadi, the temple attracts devotees seeking blessings and fulfillment of wishes. The annual fair, held in February, transforms the village into a hub of cultural festivities, music, and dance. With its serene surroundings and divine aura, the Bharadi Devi Temple offers a tranquil retreat for those in search of spiritual solace and cultural enrichment. Come, experience the sacred ambiance and timeless traditions of Agnewadi Bharadi Devi.
                        </p>
                    </div>
                </Carousel.Caption>
            </Carousel.Item>
        </Carousel>
    );
}

export default MyCarousel;
